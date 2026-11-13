<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Infrastructure\Compliance\Message\SendFolderApprovedToClientMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Informe le client que son dossier est validé par le cabinet. Volontairement sans le niveau
 * de risque LCB-FT retenu (donnée interne au cabinet). L'approbation n'a lieu qu'une fois
 * (l'entité refuse d'approuver un dossier qui n'est plus en revue) : un seul e-mail.
 *
 * Aucun équivalent pour un rejet : en LCB-FT, signaler un refus peut révéler un soupçon.
 */
#[AsEventListener]
readonly class NotifyClientOfFolderApprovedListener
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ComplianceFolderApprovedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        if (!$folder instanceof ComplianceFolder || !$folder->client instanceof Client || '' === trim($folder->client->email)) {
            return;
        }

        $this->messageBus->dispatch(new SendFolderApprovedToClientMessage(
            clientEmail: $folder->client->email,
            workspaceName: $folder->workspace->name,
            workspaceContactEmail: $folder->workspace->email,
            folderReference: $folder->reference,
            portalUrl: $this->urlGenerator->generate('portal_folders_show', ['id' => $folder->slugId], UrlGeneratorInterface::ABSOLUTE_URL),
        ));
    }
}
