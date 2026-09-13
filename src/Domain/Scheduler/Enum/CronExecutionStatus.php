<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Enum;

enum CronExecutionStatus: string
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case SKIPPED = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::RUNNING => 'En cours',
            self::SUCCESS => 'Succès',
            self::FAILURE => 'Échec',
            self::SKIPPED => 'Ignorée',
        };
    }

    /**
     * Style de badge Tailwind CSS pré-calculé pour une scannabilité immédiate dans Twig.
     */
    public function getBadgeColor(): string
    {
        return match ($this) {
            self::RUNNING => 'bg-amber-50 text-amber-700 border-amber-200',
            self::SUCCESS => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::FAILURE => 'bg-rose-50 text-rose-700 border-rose-200',
            self::SKIPPED => 'bg-slate-100 text-slate-600 border-slate-200',
        };
    }
}
