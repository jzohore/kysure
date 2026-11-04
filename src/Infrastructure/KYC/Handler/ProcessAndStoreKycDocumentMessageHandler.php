<?php

declare(strict_types=1);

namespace App\Infrastructure\KYC\Handler;

use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\KYC\Message\ProcessAndStoreKycDocumentMessage;
use App\Infrastructure\Service\ImageOptimizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
readonly class ProcessAndStoreKycDocumentMessageHandler
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $complianceDocumentRepository,
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private DocumentStorageInterface $storage,
        private ImageOptimizer $imageOptimizer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessAndStoreKycDocumentMessage $message): void
    {
        $documentUuid = Uuid::fromString($message->documentId);
        $document = $this->complianceDocumentRepository->findById($documentUuid);

        $folderUuid = Uuid::fromString($message->folderId);
        $folder = $this->complianceFolderRepository->findById($folderUuid);

        if (!$document instanceof \App\Domain\Compliance\Entity\ComplianceDocument || !file_exists($message->localTempPath)) {
            $this->logger->warning('KYC Processing failed: Document or local file missing', [
                'document_id' => $message->documentId,
                'path' => $message->localTempPath,
            ]);

            return;
        }

        $processedPath = $message->localTempPath;

        try {
            // 1. Traitement & Optimisation (Délégué à l'Infrastructure)
            if ($this->isProcessableImage($message->mimeType)) {
                $processedPath = $this->imageOptimizer->preProcessForOcr($message->localTempPath);
            }

            // 2. Préparation du fichier pour le stockage
            // Note: On utilise l'objet interne de Symfony pour l'abstraction Storage
            $fileToStore = new UploadedFile(
                $processedPath,
                $message->originalName,
                $message->mimeType,
                null,
                true // Mode test activé car fichier généré localement
            );

            // 3. Upload S3
            $directory = sprintf('compliance_folders/%s', $folder->slugId);
            $finalStoragePath = $this->storage->store($fileToStore, $directory);

            // 4. Persistance Domaine
            if ($message->isBackSide) {
                $document->markBackAsUploaded($finalStoragePath, $message->mimeType, $message->size);
            } else {
                $document->markAsUploaded($finalStoragePath, $message->originalName, $message->mimeType, $message->size);
            }
            $this->complianceDocumentRepository->save($document);

            // 5. L'ancien fichier n'est supprimé qu'une fois le nouveau stocké : un échec ne fait rien perdre.
            if ($message->oldStoragePath && $message->oldStoragePath !== $finalStoragePath) {
                $this->deleteReplacedFile($message->oldStoragePath, $message->documentId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error during KYC processing: ' . $e->getMessage());
            throw $e; // Re-throw pour la retry policy de Messenger
        } finally {
            $this->cleanupInternal($message->localTempPath, $processedPath);
        }
    }

    private function isProcessableImage(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'], true);
    }

    private function cleanupInternal(string $originalPath, string $processedPath): void
    {
        if (file_exists($originalPath)) {
            unlink($originalPath);
        }

        if ($processedPath !== $originalPath && file_exists($processedPath)) {
            unlink($processedPath);
        }
    }

    /**
     * Le nouveau fichier est déjà stocké ET enregistré : un stockage lent ou en panne au moment de purger l'ancien
     * ne doit ni faire échouer le dépôt du client ni empêcher l'analyse (qui se déclenche après ce handler). On
     * journalise le chemin orphelin pour une purge ultérieure plutôt que de perdre la pièce toute neuve.
     */
    private function deleteReplacedFile(string $oldStoragePath, string $documentId): void
    {
        try {
            $this->storage->delete($oldStoragePath);
        } catch (\Throwable $exception) {
            $this->logger->warning('KYC : ancien fichier remplacé non supprimé, à purger manuellement.', [
                'document_id' => $documentId,
                'old_storage_path' => $oldStoragePath,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
