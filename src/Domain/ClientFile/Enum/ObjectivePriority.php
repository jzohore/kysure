<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum ObjectivePriority: string
{
    case PRIMARY = 'primary';
    case SECONDARY = 'secondary';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRIMARY => 'Prioritaire',
            self::SECONDARY => 'Secondaire',
        };
    }
}
