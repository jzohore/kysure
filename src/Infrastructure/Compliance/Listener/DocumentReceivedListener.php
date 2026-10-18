<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Event\DocumentReceivedLocalEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\KYC\Handler\ProcessAndStoreKycDocumentMessageHandler;
use App\Infrastructure\KYC\Message\ProcessAndStoreKycDocumentMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Stocke la pièce déposée (optimisation image + S3) SYNCHRONEMENT, dans la requête du client.
 *
 * Pourquoi pas via Messenger : le fichier reçu est écrit dans le disque LOCAL du conteneur
 * web (`var/uploads/kyc_temp`, volume propre à chaque conteneur), que le conteneur worker ne
 * voit pas — la pièce restait indéfiniment « en traitement ». Le coût est d'une à deux
 * secondes (10 Mo max), masquées par la barre de progression côté client. L'OCR, lui, reste
 * asynchrone : il lit le fichier depuis S3.
 */
#[AsEventListener]
readonly class DocumentReceivedListener
{
    public function __construct(
        private ProcessAndStoreKycDocumentMessageHandler $storeHandler,
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DocumentReceivedLocalEvent $event): void
    {
        $document = $event->complianceDocument;
        $documentId = $document->id;
        $folderId = $event->complianceFolder->id;
        Assert::notNull($documentId);
        Assert::notNull($folderId);

        try {
            ($this->storeHandler)(new ProcessAndStoreKycDocumentMessage(
                documentId: $documentId->toString(),
                folderId: $folderId->toString(),
                localTempPath: $event->localTempPath,
                mimeType: $event->mimeType,
                originalName: $event->originalName,
                size: $event->size,
                oldStoragePath: $event->oldStoragePath,
            ));
        } catch (\Throwable $exception) {
            $this->releaseDocument($event, $exception->getMessage());

            throw new \RuntimeException('Le stockage de la pièce a échoué.', 0, $exception);
        }

        // Le handler ne lève rien si le fichier temporaire a disparu : la pièce serait restée « en traitement ».
        if (DocumentStatus::PROCESSING === $document->status) {
            $this->releaseDocument($event, 'fichier temporaire introuvable');

            throw new \RuntimeException('Le stockage de la pièce a échoué.');
        }
    }

    /**
     * La pièce ne doit jamais rester bloquée « en traitement » : le client doit pouvoir réessayer.
     */
    private function releaseDocument(DocumentReceivedLocalEvent $event, string $reason): void
    {
        $this->logger->error('Stockage de la pièce déposée en échec : la pièce est libérée.', [
            'document_id' => $event->complianceDocument->id?->toString(),
            'reason' => $reason,
        ]);

        $event->complianceDocument->markAsUploadFailed();
        $this->documentRepository->save($event->complianceDocument);
    }
}
