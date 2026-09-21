<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Domain\Scheduler\Enum\CronJob;

/**
 * Ligne de commande console exécutée pour chaque tâche planifiée. La
 * fréquence, elle, est pilotée depuis le back-office
 * ({@see \App\Domain\Scheduler\Entity\CronDefinition}).
 */
final class CronCatalog
{
    private function __construct()
    {
    }

    public static function commandLineFor(CronJob $job): string
    {
        return match ($job) {
            CronJob::PURGE_EXPIRED_INVITATIONS => 'app:invitations:purge',
            CronJob::PURGE_DER_TECHNICAL_DATA => 'app:rgpd:purge-der-technical-data',
            CronJob::MINIMIZE_SCREENING_RESULTS => 'app:rgpd:minimize-screening-results',
            CronJob::AUTO_RESOLVE_SUPPORT_THREADS => 'app:support:auto-resolve',
            CronJob::ALERT_OVERDUE_SUPPORT_THREADS => 'app:support:alert-sla-breach',
        };
    }
}
