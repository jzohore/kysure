<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Familles de produits financiers couvertes par le questionnaire de connaissances (§3.1 du
 * cahier des charges). Réduites à 5 (au lieu des 11 du cahier des charges) tant qu'il n'existe
 * pas de catalogue produits dans KYSURE — voir cadrage du lot 0 (issue #23).
 */
enum ProductFamily: string
{
    case OPCVM_ETF = 'opcvm_etf';
    case TITRES_VIFS = 'titres_vifs';
    case ASSURANCE_VIE = 'assurance_vie';
    case IMMOBILIER_SCPI = 'immobilier_scpi';
    case PRODUITS_COMPLEXES = 'produits_complexes';

    public function getLabel(): string
    {
        return match ($this) {
            self::OPCVM_ETF => 'OPCVM / ETF',
            self::TITRES_VIFS => 'Titres vifs (actions, obligations)',
            self::ASSURANCE_VIE => 'Assurance-vie',
            self::IMMOBILIER_SCPI => 'Immobilier indirect (SCPI...)',
            self::PRODUITS_COMPLEXES => 'Produits structurés / dérivés / private equity',
        };
    }
}
