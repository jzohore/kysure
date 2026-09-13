<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Enum;

/**
 * Catalogue des tâches planifiées existantes. La fréquence (expression cron)
 * et la commande console exécutée restent définies côté Infrastructure
 * ({@see \App\Infrastructure\Scheduler\CronCatalog}) : cet enum ne porte que
 * l'identité métier de la tâche.
 */
enum CronJob: string
{
    case PURGE_EXPIRED_INVITATIONS = 'purge_expired_invitations';
    case PURGE_DER_TECHNICAL_DATA = 'purge_der_technical_data';
    case MINIMIZE_SCREENING_RESULTS = 'minimize_screening_results';
    case AUTO_RESOLVE_SUPPORT_THREADS = 'auto_resolve_support_threads';

    public function getLabel(): string
    {
        return match ($this) {
            self::PURGE_EXPIRED_INVITATIONS => 'Purge des invitations collaborateur expirées',
            self::PURGE_DER_TECHNICAL_DATA => 'Purge des données techniques des accusés DER',
            self::MINIMIZE_SCREENING_RESULTS => 'Minimisation des résultats de screening',
            self::AUTO_RESOLVE_SUPPORT_THREADS => 'Clôture automatique des tickets support inactifs',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PURGE_EXPIRED_INVITATIONS => 'Anonymise les invitations jamais acceptées et périmées depuis plus de 90 jours (minimisation RGPD).',
            self::PURGE_DER_TECHNICAL_DATA => 'Efface l\'IP et le user-agent des accusés de réception DER au-delà de leur durée de conservation probatoire.',
            self::MINIMIZE_SCREENING_RESULTS => 'Retire les données brutes de tiers des résultats de screening périmés.',
            self::AUTO_RESOLVE_SUPPORT_THREADS => 'Clôture les tickets de support inactifs depuis plus de 2 heures.',
        };
    }

    /**
     * Fréquence appliquée tant qu'aucun {@see \App\Domain\Scheduler\Entity\CronDefinition}
     * n'a encore été enregistré pour cette tâche (premier déclenchement, ou
     * jamais modifiée depuis le back-office).
     */
    public function getDefaultFrequency(): CronFrequency
    {
        return match ($this) {
            self::PURGE_EXPIRED_INVITATIONS,
            self::PURGE_DER_TECHNICAL_DATA,
            self::MINIMIZE_SCREENING_RESULTS => CronFrequency::DAILY_AT_3AM,
            self::AUTO_RESOLVE_SUPPORT_THREADS => CronFrequency::EVERY_15_MINUTES,
        };
    }
}
