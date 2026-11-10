<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportMadeAvailableEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Preuve de remise sur support durable : date de mise à disposition et empreinte du fichier
 * PDF remis, dans la piste d'audit et dans l'historique du dossier. L'event n'est émis qu'une
 * fois par rapport (garde de l'entité), pas de rejeu à dédoublonner.
 */
#[AsEventListener]
readonly class AuditLogAdequacyReportMadeAvailableListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(AdequacyReportMadeAvailableEvent $event): void
    {
        $report = $this->reportRepository->findBySlugId($event->reportSlugId);
        Assert::notNull($report, 'Rapport d\'adéquation introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ADEQUACY_REPORT_MADE_AVAILABLE,
            payload: [
                'report_slug_id' => $event->reportSlugId,
                'folder_slug_id' => $event->folderSlugId,
                'client_slug_id' => $report->client->slugId,
                'version' => $event->version,
                'content_hash' => $event->contentHash,
                'pdf_sha256' => $event->pdfSha256,
                'made_available_at' => $event->madeAvailableAt->format(\DateTimeInterface::ATOM),
                'actor_type' => 'system',
            ],
            workspace: $report->workspace,
        ));

        $folder = $report->complianceFolder;
        $folder->saveHistory(
            'Rapport d\'adéquation mis à disposition du client',
            sprintf('Version %d — PDF déposé dans l\'espace client, client averti par e-mail', $event->version),
        );
        $this->folderRepository->save($folder);
    }
}
