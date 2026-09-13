<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Enum;

/**
 * Fréquences prédéfinies proposées dans le back-office. Liste fermée
 * volontairement : pas de saisie libre d'expression cron (risque d'erreur,
 * ex. une tâche lourde relancée toutes les minutes par accident).
 */
enum CronFrequency: string
{
    case EVERY_5_MINUTES = 'every_5_minutes';
    case EVERY_15_MINUTES = 'every_15_minutes';
    case EVERY_30_MINUTES = 'every_30_minutes';
    case HOURLY = 'hourly';
    case DAILY_AT_1AM = 'daily_at_1am';
    case DAILY_AT_3AM = 'daily_at_3am';
    case DAILY_AT_NOON = 'daily_at_noon';
    case WEEKLY_MONDAY_3AM = 'weekly_monday_3am';

    public function getLabel(): string
    {
        return match ($this) {
            self::EVERY_5_MINUTES => 'Toutes les 5 minutes',
            self::EVERY_15_MINUTES => 'Toutes les 15 minutes',
            self::EVERY_30_MINUTES => 'Toutes les 30 minutes',
            self::HOURLY => 'Toutes les heures',
            self::DAILY_AT_1AM => 'Tous les jours à 1h',
            self::DAILY_AT_3AM => 'Tous les jours à 3h',
            self::DAILY_AT_NOON => 'Tous les jours à midi',
            self::WEEKLY_MONDAY_3AM => 'Tous les lundis à 3h',
        };
    }

    public function toCronExpression(): string
    {
        return match ($this) {
            self::EVERY_5_MINUTES => '*/5 * * * *',
            self::EVERY_15_MINUTES => '*/15 * * * *',
            self::EVERY_30_MINUTES => '*/30 * * * *',
            self::HOURLY => '0 * * * *',
            self::DAILY_AT_1AM => '0 1 * * *',
            self::DAILY_AT_3AM => '0 3 * * *',
            self::DAILY_AT_NOON => '0 12 * * *',
            self::WEEKLY_MONDAY_3AM => '0 3 * * 1',
        };
    }
}
