<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportValidatedEvent;
use App\Infrastructure\AdequacyReport\Message\GenerateAdequacyReportPdfMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Déclenche la génération asynchrone du PDF dès la validation du rapport : le CGP n'a rien à
 * demander, le document est mis à disposition du client peu après. Pattern calqué sur
 * {@see \App\Infrastructure\Suitability\Listener\GenerateInvestorProfilePdfListener}.
 */
#[AsEventListener]
readonly class GenerateAdequacyReportPdfListener
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function __invoke(AdequacyReportValidatedEvent $event): void
    {
        $this->messageBus->dispatch(new GenerateAdequacyReportPdfMessage($event->reportSlugId, $event->contentHash));
    }
}
