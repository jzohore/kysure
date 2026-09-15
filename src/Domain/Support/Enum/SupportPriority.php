<?php

declare(strict_types=1);

namespace App\Domain\Support\Enum;

enum SupportPriority: string
{
    case NORMAL = 'normal';
    case URGENT = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::URGENT => 'Urgent',
        };
    }

    public function getBadgeClasses(): string
    {
        return match ($this) {
            self::NORMAL => 'bg-slate-100 text-slate-600',
            self::URGENT => 'bg-rose-100 text-rose-700',
        };
    }

    /**
     * Délai de première réponse promis au client selon la priorité — sert à calculer
     * `SupportThread::$dueAt`. NORMAL reprend la promesse marketing existante (2h).
     */
    public function getResponseDelay(): \DateInterval
    {
        return match ($this) {
            self::NORMAL => new \DateInterval('PT2H'),
            self::URGENT => new \DateInterval('PT30M'),
        };
    }
}
