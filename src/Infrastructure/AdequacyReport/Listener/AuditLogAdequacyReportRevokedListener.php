<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportRevokedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

#[AsEventListener]
readonly class AuditLogAdequacyReportRevokedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
    ) {
    }

    public function __invoke(AdequacyReportRevokedEvent $event): void
    {
        $report = $this->reportRepository->findBySlugId($event->reportSlugId);
        Assert::notNull($report, 'Rapport d\'adéquation introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ADEQUACY_REPORT_REVOKED,
            payload: [
                'report_slug_id' => $event->reportSlugId,
                'folder_slug_id' => $event->folderSlugId,
                'version' => $event->version,
                'reason' => $event->reason,
                'content_hash' => $report->contentHash,
                'actor_name' => $event->revokedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $report->workspace,
        ));
    }
}
