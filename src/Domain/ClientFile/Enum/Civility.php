<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum Civility: string
{
    case MR = 'mr';
    case MRS = 'mrs';

    public function getLabel(): string
    {
        return match ($this) {
            self::MR => 'M.',
            self::MRS => 'Mme',
        };
    }
}
