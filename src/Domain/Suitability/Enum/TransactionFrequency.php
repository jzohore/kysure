<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Fréquence déclarée des investissements passés (§3.2). La valeur entière sert de score
 * brut (0 à 3) au moteur de notation de l'expérience.
 */
enum TransactionFrequency: int
{
    case JAMAIS = 0;
    case OCCASIONNELLE = 1;
    case REGULIERE = 2;
    case FREQUENTE = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::JAMAIS => 'Jamais investi',
            self::OCCASIONNELLE => 'Occasionnelle',
            self::REGULIERE => 'Régulière',
            self::FREQUENTE => 'Fréquente',
        };
    }
}
