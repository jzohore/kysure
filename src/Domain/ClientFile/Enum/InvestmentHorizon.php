<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum InvestmentHorizon: string
{
    case UNDER_3_YEARS = 'under_3_years';
    case FROM_3_TO_5_YEARS = 'from_3_to_5_years';
    case FROM_5_TO_8_YEARS = 'from_5_to_8_years';
    case FROM_8_TO_10_YEARS = 'from_8_to_10_years';
    case OVER_10_YEARS = 'over_10_years';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNDER_3_YEARS => 'Moins de 3 ans',
            self::FROM_3_TO_5_YEARS => '3 à 5 ans',
            self::FROM_5_TO_8_YEARS => '5 à 8 ans',
            self::FROM_8_TO_10_YEARS => '8 à 10 ans',
            self::OVER_10_YEARS => 'Plus de 10 ans',
        };
    }
}
