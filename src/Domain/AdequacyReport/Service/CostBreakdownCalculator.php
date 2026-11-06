<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Service;

use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\AdequacyReport\ValueObject\CostBreakdown;
use App\Domain\AdequacyReport\ValueObject\ProductCharacteristics;

/**
 * Calcul des frais d'un investissement (lot 4, issue #31) : montant investi × produit × horizon.
 *
 * Périmètre MVP (décision du fondateur) : les FRAIS seulement. Aucune projection ni scénario
 * de performance — KYSURE documente le conseil et ne calcule jamais de rendement maison ; pour
 * un produit PRIIPS, les scénarios réglementaires sont ceux du DIC.
 *
 * Choix de calcul (à valider avec le CGP pilote, toute évolution incrémente {@see self::VERSION}) :
 * - Horizon = horizon minimum du produit, c'est-à-dire sa durée de détention recommandée (DIC).
 *   C'est la durée de référence des coûts MIF2/PRIIPS ; la tranche d'horizon de l'objectif est
 *   une fourchette (et « plus de 10 ans » n'a pas de borne haute), elle ne donne pas une durée
 *   unique. Un produit sans horizon minimum (0 an) est compté sur 1 an.
 * - Effet cumulé = frais annuels × nombre d'années, sur le montant net des droits d'entrée,
 *   à capital constant : sans hypothèse de performance, le calcul reste vérifiable à la main.
 * - Les rétrocessions sont comprises dans les frais du produit : elles sont divulguées mais
 *   jamais ajoutées au coût total (sinon elles seraient comptées deux fois).
 * - Arrondi au centime le plus proche, demi vers le haut.
 *
 * Fonction pure : mêmes entrées → même décomposition.
 */
final class CostBreakdownCalculator
{
    public const string VERSION = 'costs-v1';

    private const int BASIS_POINTS = 10_000;

    /** Durée minimale de calcul, pour un produit sans horizon minimum renseigné. */
    private const int MIN_HORIZON_YEARS = 1;

    /** Règle de calcul en clair, pour la bulle d'aide de l'écran. */
    public static function ruleDescription(): string
    {
        return sprintf(
            'Frais annuels du produit (catalogue) appliqués au montant net des droits d\'entrée, multipliés par l\'horizon minimum du produit (%d an au moins), à capital constant : aucune hypothèse de performance. Coût total = droits d\'entrée + honoraires de conseil + frais annuels cumulés ; les rétrocessions, déjà comprises dans les frais du produit, sont affichées sans être ajoutées.',
            self::MIN_HORIZON_YEARS,
        );
    }

    /**
     * @throws \DomainException si les rétrocessions dépassent les frais annuels du produit
     */
    public function calculate(ProductCharacteristics $product, int $amountInCents, CifRemuneration $remuneration): CostBreakdown
    {
        if ($amountInCents <= 0) {
            throw new \DomainException('Indiquez un montant à investir.');
        }

        if ($remuneration->annualRetrocessionBasisPoints > $product->annualFeesBasisPoints) {
            throw new \DomainException(sprintf('Les rétrocessions (%s) ne peuvent pas dépasser les frais annuels du produit (%s) : elles en sont une part.', $this->percent($remuneration->annualRetrocessionBasisPoints), $this->percent($product->annualFeesBasisPoints)));
        }

        $horizonYears = max(self::MIN_HORIZON_YEARS, $product->minimumHorizonYears);
        $entryFee = $this->applyRate($amountInCents, $remuneration->entryFeeBasisPoints);
        $netInvested = $amountInCents - $entryFee;
        $annualFees = $this->applyRate($netInvested, $product->annualFeesBasisPoints);
        $annualRetrocession = $this->applyRate($netInvested, $remuneration->annualRetrocessionBasisPoints);
        $cumulativeAnnualFees = $annualFees * $horizonYears;
        $totalCosts = $entryFee + $remuneration->advisoryFeeInCents + $cumulativeAnnualFees;

        return new CostBreakdown(
            calculatorVersion: self::VERSION,
            amountInCents: $amountInCents,
            horizonYears: $horizonYears,
            cifRemuneration: $remuneration,
            entryFeeInCents: $entryFee,
            annualFeesBasisPoints: $product->annualFeesBasisPoints,
            annualFeesInCents: $annualFees,
            cumulativeAnnualFeesInCents: $cumulativeAnnualFees,
            annualRetrocessionInCents: $annualRetrocession,
            cumulativeRetrocessionInCents: $annualRetrocession * $horizonYears,
            totalCostsInCents: $totalCosts,
            totalCostsBasisPoints: $this->roundedDivision($totalCosts * self::BASIS_POINTS, $amountInCents),
        );
    }

    /** Montant × taux en points de base, arrondi au centime le plus proche. */
    private function applyRate(int $amountInCents, int $basisPoints): int
    {
        return $this->roundedDivision($amountInCents * $basisPoints, self::BASIS_POINTS);
    }

    /** Division entière de nombres positifs, arrondie demi vers le haut. */
    private function roundedDivision(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }

    private function percent(int $basisPoints): string
    {
        return sprintf('%d,%02d %%', intdiv($basisPoints, 100), $basisPoints % 100);
    }
}
