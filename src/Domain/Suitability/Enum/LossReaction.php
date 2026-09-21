<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Réaction déclarée du client face à un scénario de perte (§3.4 — tolérance au risque).
 * La valeur entière sert de score brut (0 à 3) au moteur de notation, dans l'ordre croissant
 * de tolérance : vendre tout est la réaction la moins tolérante au risque, renforcer la
 * position la plus tolérante.
 */
enum LossReaction: int
{
    case VEND_TOUT = 0;
    case REDUIT_LA_POSITION = 1;
    case NE_FAIT_RIEN = 2;
    case RENFORCE_LA_POSITION = 3;

    public function getLabel(): string
    {
        return match ($this) {
            self::VEND_TOUT => 'Vend tout pour stopper la perte',
            self::REDUIT_LA_POSITION => 'Réduit la position',
            self::NE_FAIT_RIEN => 'Ne fait rien, attend',
            self::RENFORCE_LA_POSITION => 'Renforce la position',
        };
    }
}
