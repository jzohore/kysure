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
}
