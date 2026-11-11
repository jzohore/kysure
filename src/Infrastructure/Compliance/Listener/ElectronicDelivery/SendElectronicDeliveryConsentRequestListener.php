<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\ElectronicDelivery;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ElectronicDeliveryConsentRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Compliance\Message\SendElectronicDeliveryConsentRequestMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Met en file l'e-mail qui invite le client à donner son accord dans son espace. Une demande
 * = un e-mail ; la garde de 24 h de l'entité empêche les relances en rafale.
 */
#[AsEventListener]
readonly class SendElectronicDeliveryConsentRequestListener
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ElectronicDeliveryConsentRequestedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        if (!$folder instanceof ComplianceFolder || !$folder->client instanceof Client || '' === trim($folder->client->email)) {
            return;
        }

        $this->messageBus->dispatch(new SendElectronicDeliveryConsentRequestMessage(
            clientEmail: $folder->client->email,
            workspaceName: $folder->workspace->name,
            workspaceContactEmail: $folder->workspace->email,
            portalUrl: $this->urlGenerator->generate('portal_folders_show', ['id' => $folder->slugId], UrlGeneratorInterface::ABSOLUTE_URL),
        ));
    }
}
