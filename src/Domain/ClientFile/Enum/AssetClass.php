<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

/**
 * Poches patrimoniales de la fiche client (EER lot 1) — volontairement limitées à 6 grandes
 * catégories (cible actée avec le CGP pilote : 6 à 8 poches, jamais un détail ligne par ligne
 * façon relevé de comptes), sur le modèle des lignes déjà validées dans la maquette du lot 0.
 */
enum AssetClass: string
{
    case REAL_ESTATE = 'real_estate';
    case LIFE_INSURANCE = 'life_insurance';
    case PEA = 'pea';
    case SECURITIES_ACCOUNT = 'securities_account';
    case REGULATED_SAVINGS = 'regulated_savings';
    case AVAILABLE_LIQUIDITY = 'available_liquidity';

    public function getLabel(): string
    {
        return match ($this) {
            self::REAL_ESTATE => 'Patrimoine immobilier',
            self::LIFE_INSURANCE => 'Assurance-vie',
            self::PEA => 'PEA',
            self::SECURITIES_ACCOUNT => 'Compte-titres',
            self::REGULATED_SAVINGS => 'Livrets / épargne réglementée',
            self::AVAILABLE_LIQUIDITY => 'Liquidités disponibles',
        };
    }
}
