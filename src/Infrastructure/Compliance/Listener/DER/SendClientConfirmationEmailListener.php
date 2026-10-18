<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\DER;

use App\Domain\Compliance\Event\DerAcknowledgedEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Infrastructure\Compliance\Message\SendClientDerConfirmationMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

#[AsEventListener]
readonly class SendClientConfirmationEmailListener
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
        private ComplianceDocumentRepositoryInterface $complianceDocumentRepository,
    ) {
    }

    public function __invoke(DerAcknowledgedEvent $event): void
    {
        $document = $this->complianceDocumentRepository->findById($event->getDocumentId());

        Assert::notNull($document);

        $folder = $document->folder;

        Assert::notNull($folder);
        Assert::notNull($document->id);

        // Lien profond vers le dépôt des pièces : le firewall du portail demande la connexion
        // (lien magique par e-mail) si le client n'est pas déjà authentifié.
        $url = $this->urlGenerator->generate('app_portal_folder_detail', ['id' => $folder->slugId], UrlGeneratorInterface::ABSOLUTE_URL);

        Assert::notNull($folder->id);
        $this->messageBus->dispatch(new SendClientDerConfirmationMessage(
            portalUrl: $url,
            folderId: $folder->id->toString(),
        ));
    }
}
