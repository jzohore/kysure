<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportMadeAvailableEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Infrastructure\AdequacyReport\Message\SendAdequacyReportAvailableMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * Avertit le client par e-mail que son rapport est disponible, avec le lien vers l'espace
 * sécurisé (art. 3 §2 du règlement délégué 2017/565 : un site internet ne vaut support durable
 * que si le client est averti de l'adresse du document). Aucun contenu du rapport dans
 * l'e-mail. Un seul e-mail par rapport : l'event n'est émis qu'une fois.
 */
#[AsEventListener]
readonly class NotifyClientAdequacyReportAvailableListener
{
    public function __construct(
        private AdequacyReportRepositoryInterface $reportRepository,
        private MessageBusInterface $messageBus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(AdequacyReportMadeAvailableEvent $event): void
    {
        $report = $this->reportRepository->findBySlugId($event->reportSlugId);
        Assert::notNull($report, 'Rapport d\'adéquation introuvable pour la notification client.');

        $email = $report->client->email;
        if ('' === trim($email)) {
            return;
        }

        $this->messageBus->dispatch(new SendAdequacyReportAvailableMessage(
            clientEmail: $email,
            workspaceName: $report->workspace->name,
            workspaceContactEmail: $report->workspace->email,
            portalUrl: $this->urlGenerator->generate('portal_folders_show', ['id' => $event->folderSlugId], UrlGeneratorInterface::ABSOLUTE_URL),
            version: $event->version,
        ));
    }
}
