<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Enum;

/**
 * Contrôles du moteur d'adéquation (lot 3), dans l'ordre d'affichage. Chaque contrôle produit
 * exactement un constat par vérification.
 */
enum MatchCriterion: string
{
    case RISK_LEVEL = 'risk_level';
    case TARGET_MARKET = 'target_market';
    case HORIZON = 'horizon';
    case KNOWLEDGE_EXPERIENCE = 'knowledge_experience';
    case INVESTMENT_CAPACITY = 'investment_capacity';
    case CONCENTRATION = 'concentration';
    case LIQUIDITY = 'liquidity';
    case SUSTAINABILITY = 'sustainability';

    public function getLabel(): string
    {
        return match ($this) {
            self::RISK_LEVEL => 'Risque du produit',
            self::TARGET_MARKET => 'Marché cible du produit',
            self::HORIZON => 'Horizon de placement',
            self::KNOWLEDGE_EXPERIENCE => 'Connaissance et expérience',
            self::INVESTMENT_CAPACITY => 'Capacité d\'investissement',
            self::CONCENTRATION => 'Concentration du patrimoine',
            self::LIQUIDITY => 'Liquidité résiduelle',
            self::SUSTAINABILITY => 'Préférence de durabilité',
        };
    }
}
