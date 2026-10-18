<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceDocument;

use App\Application\Compliance\DTO\Request\UploadDocumentRequest;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Event\ClientDocumentUploadedEvent;
use App\Domain\Compliance\Event\DocumentMalwareDetectedEvent;
use App\Domain\Compliance\Event\DocumentReceivedLocalEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Exception\Document\MalwareDetectedException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Port\VirusScannerInterface;
use App\Infrastructure\Service\StorageService;

use function Symfony\Component\Clock\now;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt d'une pièce justificative par le client sur son espace.
 *
 * Le contrôle d'appartenance du dossier au client est fait en amont (voter) ;
 * ce use case garde les règles métier : collecte ouverte, pièce bien demandée,
 * pièce ni validée ni en cours de traitement. Le fichier est passé à l'antivirus
 * AVANT tout stockage : un fichier infecté, ou non analysé, est refusé.
 */
readonly class UploadDocumentUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $repository,
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private EventDispatcherInterface $eventDispatcher,
        private StorageService $storageService,
        private VirusScannerInterface $virusScanner,
        private string $tempStorageDir,
    ) {
    }

    public function __invoke(UploadDocumentRequest $request): void
    {
        $documentUuid = Uuid::fromString($request->documentId);
        $document = $this->repository->findById($documentUuid);

        $folderUuid = Uuid::fromString($request->folderId);
        $folder = $this->complianceFolderRepository->findById($folderUuid);

        if (!$document instanceof ComplianceDocument) {
            throw DocumentNotFoundException::withId($request->documentId);
        }

        if ($document->folder !== $folder) {
            throw InvalidDocumentFolderException::forDocument($request->documentId, $folder->slugId);
        }

        if (!$folder->canReceiveClientDocuments()) {
            throw new \DomainException('Le dépôt de pièces n\'est pas ouvert sur ce dossier.');
        }

        // 🛡️ Anti double-envoi : une pièce en cours de traitement ou déjà validée n'est pas remplaçable.
        if (!$document->canBeReplacedByClient()) {
            throw new \DomainException('Cette pièce ne peut pas être déposée ou remplacée dans son état actuel.');
        }

        // Empreinte du fichier tel que reçu, calculée avant tout déplacement ou traitement d'image.
        $sha256 = hash_file('sha256', $request->file->getPathname());
        if (false === $sha256) {
            throw new \DomainException('Le fichier reçu est illisible. Merci de réessayer.');
        }

        // 🛡️ Antivirus avant tout déplacement ou stockage (fail-closed si l'analyse échoue).
        try {
            $this->virusScanner->scan($request->file->getPathname());
        } catch (MalwareDetectedException $exception) {
            $this->eventDispatcher->dispatch(new DocumentMalwareDetectedEvent(
                folderSlugId: $folder->slugId,
                documentType: $document->type->value,
                signature: $exception->signature,
                sha256: $sha256,
                detectedAt: now(),
            ));

            throw $exception;
        }

        // Type MIME réel (sniffing du contenu), pas celui déclaré par le navigateur.
        $auditedMimeType = $request->file->getMimeType() ?? $request->file->getClientMimeType();

        $oldStoragePath = $document->storagePath; // À conserver pour la suppression asynchrone future

        // 1. Stockage Local Temporaire (Instantané)
        $niceName = sprintf(
            '%s-%s-%s.%s',
            $folder->reference, // Ex: "DOS-2026-05"
            $document->type->value, // Ex: "id_card"
            uniqid(),
            $request->file->guessExtension()
        );

        $localTempPath = $this->storageService->storeLocally($request->file, $this->tempStorageDir, 'compliance_fol_');

        // 2. Mise à jour de l'état (On enregistre le chemin local temporaire en attendant le S3)
        $document->markAsProcessed();
        $this->repository->save($document);

        $this->eventDispatcher->dispatch(new ClientDocumentUploadedEvent(
            documentId: $request->documentId,
            folderSlugId: $folder->slugId,
            documentType: $document->type->value,
            mimeType: $auditedMimeType,
            size: (int) filesize($localTempPath),
            sha256: $sha256,
            uploadedAt: now(),
        ));

        $this->eventDispatcher->dispatch(new DocumentReceivedLocalEvent(
            complianceDocument: $document,
            complianceFolder: $folder,
            localTempPath: $localTempPath,
            mimeType: $auditedMimeType,
            originalName: $niceName,
            size: (int) filesize($localTempPath),
            oldStoragePath: $oldStoragePath
        ));
    }
}
