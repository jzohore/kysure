<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Horizon de placement déclaré (§1.5 / §3.5). {@see self::midpointYears()} fournit une
 * durée représentative en années, utilisée par le moteur de notation pour la capacité à
 * subir des pertes (un horizon plus long absorbe mieux une perte temporaire).
 */
enum InvestmentHorizon: string
{
    case MOINS_DE_3_ANS = 'moins_3_ans';
    case DE_3_A_5_ANS = '3_5_ans';
    case DE_5_A_8_ANS = '5_8_ans';
    case DE_8_A_10_ANS = '8_10_ans';
    case PLUS_DE_10_ANS = 'plus_10_ans';

    public function getLabel(): string
    {
        return match ($this) {
            self::MOINS_DE_3_ANS => 'Moins de 3 ans',
            self::DE_3_A_5_ANS => '3 à 5 ans',
            self::DE_5_A_8_ANS => '5 à 8 ans',
            self::DE_8_A_10_ANS => '8 à 10 ans',
            self::PLUS_DE_10_ANS => 'Plus de 10 ans',
        };
    }

    public function midpointYears(): float
    {
        return match ($this) {
            self::MOINS_DE_3_ANS => 1.5,
            self::DE_3_A_5_ANS => 4.0,
            self::DE_5_A_8_ANS => 6.5,
            self::DE_8_A_10_ANS => 9.0,
            self::PLUS_DE_10_ANS => 12.0,
        };
    }
}
