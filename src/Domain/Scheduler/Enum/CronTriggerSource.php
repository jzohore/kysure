<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Enum;

enum CronTriggerSource: string
{
    case SCHEDULE = 'schedule';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::SCHEDULE => 'Planification automatique',
            self::MANUAL => 'Déclenchement manuel',
        };
    }
}
