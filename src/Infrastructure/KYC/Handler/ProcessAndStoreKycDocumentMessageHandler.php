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
            // 1. Nettoyage de l'ancien fichier S3
            if ($message->oldStoragePath) {
                $this->storage->delete($message->oldStoragePath);
            }

            // 2. Traitement & Optimisation (Délégué à l'Infrastructure)
            if ($this->isProcessableImage($message->mimeType)) {
                $processedPath = $this->imageOptimizer->preProcessForOcr($message->localTempPath);
            }

            // 3. Préparation du fichier pour le stockage
            // Note: On utilise l'objet interne de Symfony pour l'abstraction Storage
            $fileToStore = new UploadedFile(
                $processedPath,
                $message->originalName,
                $message->mimeType,
                null,
                true // Mode test activé car fichier généré localement
            );

            // 4. Upload S3
            $directory = sprintf('compliance_folders/%s', $folder->slugId);
            $finalStoragePath = $this->storage->store($fileToStore, $directory);

            // 5. Persistance Domaine
            $document->markAsUploaded($finalStoragePath, $message->originalName, $message->mimeType, $message->size);
            $this->complianceDocumentRepository->save($document);
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
}
