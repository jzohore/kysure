<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Listener;

use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Une analyse OCR qui échoue définitivement (retries épuisés : erreur AWS, limitation, réseau)
 * partirait sinon dans le transport `failed` sans aucun signal côté cabinet. On pose alors un
 * point de vigilance sur la pièce — qui reste « à vérifier », rien n'est rejeté.
 */
#[AsEventListener]
readonly class MarkOcrUnavailableOnFailureListener
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        // Un nouvel essai est prévu : le signal ne sert qu'à l'échec définitif.
        if (!$message instanceof ProcessOcrMessage || $event->willRetry()) {
            return;
        }

        try {
            $document = $this->repository->findById($message->documentId);

            // Idempotent : un échec rejoué (messenger:failed:retry) ne re-signale ni ne ré-audite.
            if (!$document instanceof \App\Domain\Compliance\Entity\ComplianceDocument || !$document->markOcrUnavailable()) {
                return;
            }

            $this->repository->save($document);
            $this->eventDispatcher->dispatch(new DocumentOcrUnavailableEvent($document));
        } catch (\Throwable $exception) {
            // Ne jamais masquer l'échec d'origine ni faire planter le worker.
            $this->logger->error('OCR : impossible de signaler l\'échec définitif de l\'analyse.', [
                'document_id' => $message->documentId,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
