<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\AdequacyReportValidatedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Piste d'audit AMF de la validation d'un rapport d'adéquation. Synchrone : l'event n'est émis
 * qu'une fois par validation (ligne en ajout seul), pas de rejeu à dédoublonner.
 */
#[AsEventListener]
readonly class AuditLogAdequacyReportValidatedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
    ) {
    }

    public function __invoke(AdequacyReportValidatedEvent $event): void
    {
        $report = $this->reportRepository->findBySlugId($event->reportSlugId);
        Assert::notNull($report, 'Rapport d\'adéquation introuvable pour la piste d\'audit.');

        $checks = $report->content['checks'] ?? [];

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ADEQUACY_REPORT_VALIDATED,
            payload: [
                'report_slug_id' => $event->reportSlugId,
                'folder_slug_id' => $event->folderSlugId,
                'client_slug_id' => $report->client->slugId,
                'version' => $event->version,
                'investor_profile_slug_id' => $event->investorProfileSlugId,
                // Empreintes des vérifications citées : prouvent quelles versions exactes fondent le rapport.
                'checks' => \is_array($checks) ? array_map(
                    static fn (mixed $check): array => \is_array($check) ? ['slug_id' => $check['slugId'] ?? null, 'content_hash' => $check['contentHash'] ?? null] : [],
                    array_values($checks),
                ) : [],
                'content_hash' => $event->contentHash,
                'actor_name' => $event->validatedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $report->workspace,
        ));
    }
}
