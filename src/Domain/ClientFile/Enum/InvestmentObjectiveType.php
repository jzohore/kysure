<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Enum;

enum InvestmentObjectiveType: string
{
    case WEALTH_BUILDING = 'wealth_building';
    case CAPITAL_GROWTH = 'capital_growth';
    case RETIREMENT_PREPARATION = 'retirement_preparation';
    case SUPPLEMENTARY_INCOME = 'supplementary_income';
    case WEALTH_TRANSFER = 'wealth_transfer';
    case DIVERSIFICATION = 'diversification';
    case CAPITAL_PROTECTION = 'capital_protection';
    case TAX_OPTIMIZATION = 'tax_optimization';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WEALTH_BUILDING => 'Constitution de patrimoine',
            self::CAPITAL_GROWTH => 'Valorisation du capital',
            self::RETIREMENT_PREPARATION => 'Préparation de la retraite',
            self::SUPPLEMENTARY_INCOME => 'Génération de revenus complémentaires',
            self::WEALTH_TRANSFER => 'Transmission',
            self::DIVERSIFICATION => 'Diversification',
            self::CAPITAL_PROTECTION => 'Protection du capital',
            self::TAX_OPTIMIZATION => 'Optimisation fiscale',
            self::OTHER => 'Autre',
        };
    }
}
