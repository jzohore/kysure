<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderSubmittedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Infrastructure\Compliance\Message\DispatchNotifyFolderSubmitted;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient le cabinet quand le CLIENT soumet son dossier : sans ça, le CGP ne le découvrait
 * qu'en ouvrant le dossier. Destinataire : le conseiller assigné s'il y en a un, sinon les
 * administrateurs du cabinet. Une soumission faite par le CGP lui-même ne notifie personne.
 */
#[AsEventListener]
readonly class NotifyWorkspaceOfFolderSubmittedListener
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ComplianceFolderSubmittedEvent $event): void
    {
        if (ComplianceFolderSubmittedEvent::ACTOR_CLIENT !== $event->actorType) {
            return;
        }

        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        if (!$folder instanceof ComplianceFolder) {
            return;
        }

        $recipients = $folder->assignedReviewer instanceof User
            ? [$folder->assignedReviewer]
            : $this->workspaceMemberRepository->findMembersAdmin($folder->workspace);

        if ([] === $recipients) {
            $this->logger->critical('NotifyWorkspaceOfFolderSubmitted : aucun destinataire pour le cabinet.', ['folder_slug_id' => $folder->slugId]);

            return;
        }

        $clientName = $folder->client instanceof Client ? $folder->client->getFullName() : $folder->reference;
        $reviewUrl = $this->urlGenerator->generate('app_compliance_show', ['slugId' => $folder->slugId], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($recipients as $recipient) {
            if ('' === trim($recipient->email)) {
                continue;
            }

            $this->messageBus->dispatch(new DispatchNotifyFolderSubmitted(
                recipientEmail: $recipient->email,
                clientName: $clientName,
                folderReference: $folder->reference,
                reviewUrl: $reviewUrl,
            ));
        }
    }
}
