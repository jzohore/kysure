<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportOpenedByClientEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Trace chaque ouverture du rapport par le client : c'est la preuve de remise du document sur
 * support durable. Une entrée par ouverture (journal d'accès, pas d'état à dédoublonner).
 */
#[AsEventListener]
readonly class AuditLogAdequacyReportOpenedByClientListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
    ) {
    }

    public function __invoke(AdequacyReportOpenedByClientEvent $event): void
    {
        $report = $this->reportRepository->findBySlugId($event->reportSlugId);
        Assert::notNull($report, 'Rapport d\'adéquation introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ADEQUACY_REPORT_OPENED_BY_CLIENT,
            payload: [
                'report_slug_id' => $event->reportSlugId,
                'client_slug_id' => $event->clientSlugId,
                'version' => $event->version,
                'content_hash' => $event->contentHash,
                'pdf_sha256' => $event->pdfSha256,
                'actor_type' => 'client',
            ],
            workspace: $report->workspace,
        ));
    }
}
