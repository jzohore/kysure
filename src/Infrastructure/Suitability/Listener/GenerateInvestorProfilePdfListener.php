<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Listener;

use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Infrastructure\Suitability\Message\GenerateInvestorProfilePdfMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Déclenche la génération asynchrone du PDF de synthèse (déclaration d'adéquation) dès qu'un
 * profil investisseur est validé — le CGP n'a rien à demander explicitement, le document est
 * prêt au téléchargement peu après la validation. Pattern calqué sur la génération du DER
 * (voir {@see \App\Infrastructure\Compliance\Handler\GenerateDerPdfHandler}).
 */
#[AsEventListener]
readonly class GenerateInvestorProfilePdfListener
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function __invoke(InvestorProfileValidatedEvent $event): void
    {
        $this->messageBus->dispatch(new GenerateInvestorProfilePdfMessage($event->profileSlugId));
    }
}
