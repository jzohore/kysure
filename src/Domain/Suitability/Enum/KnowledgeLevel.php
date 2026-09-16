<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Niveau de connaissance déclaré par le client pour une {@see ProductFamily} donnée (§3.1).
 * La valeur entière sert directement de score brut (0 à 4) au moteur de notation.
 */
enum KnowledgeLevel: int
{
    case AUCUNE = 0;
    case LIMITEE = 1;
    case INTERMEDIAIRE = 2;
    case BONNE = 3;
    case EXPERTISE = 4;

    public function getLabel(): string
    {
        return match ($this) {
            self::AUCUNE => 'Aucune connaissance',
            self::LIMITEE => 'Connaissance limitée',
            self::INTERMEDIAIRE => 'Connaissance intermédiaire',
            self::BONNE => 'Bonne connaissance',
            self::EXPERTISE => 'Expertise',
        };
    }
}
