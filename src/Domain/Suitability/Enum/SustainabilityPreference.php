<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Préférence de durabilité déclarée (§3.7, obligatoire depuis le règlement délégué MiFID II
 * du 02/08/2022). L'absence de préférence doit être une réponse explicite du client, jamais
 * une valeur par défaut ou un champ laissé vide — d'où l'absence de cas « non renseigné ».
 */
enum SustainabilityPreference: string
{
    case INTERESSE = 'interesse';
    case NON_INTERESSE = 'non_interesse';
    case SANS_PREFERENCE = 'sans_preference';

    public function getLabel(): string
    {
        return match ($this) {
            self::INTERESSE => 'Intéressé par les investissements durables',
            self::NON_INTERESSE => 'Non intéressé par les investissements durables',
            self::SANS_PREFERENCE => 'Sans préférence exprimée',
        };
    }
}
