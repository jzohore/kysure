<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\Document;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Event\DocumentCorrectionsRequestedEvent;
use App\Infrastructure\Compliance\Message\SendDocumentCorrectionsRequestMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * Met en file l'e-mail groupé de demande de corrections. Une seule demande = un seul e-mail : l'entité ne
 * resignale jamais une pièce déjà signalée, donc un rejeu n'envoie rien de plus.
 */
#[AsEventListener]
readonly class SendDocumentCorrectionsRequestListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(DocumentCorrectionsRequestedEvent $event): void
    {
        Assert::notNull($event->folder->id);

        // Lien profond vers le dépôt : le portail demande la connexion (lien magique) si besoin.
        $portalUrl = $this->urlGenerator->generate('app_portal_folder_detail', ['id' => $event->folder->slugId], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->messageBus->dispatch(new SendDocumentCorrectionsRequestMessage(
            folderId: $event->folder->id->toString(),
            documentIds: array_values(array_filter(array_map(
                static fn (ComplianceDocument $doc): ?string => $doc->id?->toString(),
                $event->documents,
            ))),
            portalUrl: $portalUrl,
        ));
    }
}
