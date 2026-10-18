<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Event\DocumentOcrProcessedEvent;
use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use App\Domain\Shared\Port\RealTimeNotifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Prévient la fiche dossier ouverte par le CGP (topic Mercure `folder_<slug>`) qu'une analyse
 * vient de se terminer, pour qu'elle se rafraîchisse sans rechargement manuel.
 *
 * Purement cosmétique : un Mercure indisponible ne doit jamais faire échouer ni rejouer l'analyse
 * (déjà enregistrée et auditée).
 */
readonly class NotifyFolderOnOcrAnalysisListener
{
    public function __construct(
        private RealTimeNotifierInterface $notifier,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onProcessed(DocumentOcrProcessedEvent $event): void
    {
        $this->notify($event->document);
    }

    #[AsEventListener]
    public function onUnavailable(DocumentOcrUnavailableEvent $event): void
    {
        $this->notify($event->document);
    }

    private function notify(ComplianceDocument $document): void
    {
        try {
            $this->notifier->notify('folder_' . $document->folder->slugId, ['action' => 'document_ocr_analysed']);
        } catch (\Throwable $exception) {
            $this->logger->warning('Mercure : notification de la fiche dossier impossible.', [
                'document_id' => $document->id?->toString(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
