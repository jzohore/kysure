<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use Webmozart\Assert\Assert;

/**
 * Données financières objectives permettant de calculer la capacité à subir des pertes
 * (§3.5). Volontairement distincte de {@see RiskToleranceAnswers} : un client peut se dire
 * prêt à prendre des risques (tolérance) sans en avoir les moyens (capacité) — c'est cette
 * capacité qui doit plafonner le profil final, jamais l'appétence déclarée.
 */
final readonly class LossCapacityInputs
{
    /**
     * Nombre de mois de charges couverts par la trésorerie disponible au-delà duquel le
     * coussin de liquidité est considéré comme plein (contribution maximale au score).
     */
    private const float FULL_LIQUIDITY_BUFFER_MONTHS = 12.0;

    /**
     * Horizon (en années) au-delà duquel la capacité de récupération est considérée comme
     * maximale (contribution maximale au score).
     */
    private const float FULL_RECOVERY_HORIZON_YEARS = 12.0;

    private function __construct(
        public float $annualIncome,
        public float $annualExpenses,
        public float $netWorth,
        public float $availableLiquidity,
        public float $amountToInvest,
        public InvestmentHorizon $horizon,
    ) {
        Assert::greaterThanEq($this->annualIncome, 0.0, 'Les revenus annuels ne peuvent pas être négatifs.');
        Assert::greaterThanEq($this->annualExpenses, 0.0, 'Les charges annuelles ne peuvent pas être négatives.');
        Assert::greaterThanEq($this->netWorth, 0.0, 'Le patrimoine net ne peut pas être négatif.');
        Assert::greaterThanEq($this->availableLiquidity, 0.0, 'Les liquidités disponibles ne peuvent pas être négatives.');
        Assert::greaterThan($this->amountToInvest, 0.0, 'Le montant à investir doit être strictement positif.');
    }

    public static function fromInputs(
        float $annualIncome,
        float $annualExpenses,
        float $netWorth,
        float $availableLiquidity,
        float $amountToInvest,
        InvestmentHorizon $horizon,
    ): self {
        return new self($annualIncome, $annualExpenses, $netWorth, $availableLiquidity, $amountToInvest, $horizon);
    }

    /**
     * Score brut sur une échelle 0 à 4, homogène avec les autres dimensions, construit à
     * partir de 4 proxies à poids égal :
     *  - le taux d'épargne (revenus - charges, rapporté aux revenus) ;
     *  - le coussin de liquidité restant après investissement, en mois de charges couvertes ;
     *  - la part du patrimoine total (liquidités + patrimoine net) mobilisée par l'investissement
     *    envisagé — plus elle est faible, plus la capacité est élevée ;
     *  - l'horizon de placement, qui donne le temps d'absorber une perte temporaire.
     */
    public function score(): float
    {
        $savingsRatio = $this->annualIncome > 0.0
            ? max(0.0, $this->annualIncome - $this->annualExpenses) / $this->annualIncome
            : 0.0;

        $monthlyExpenses = max($this->annualExpenses / 12, 1.0);
        $liquidityAfterInvestment = max(0.0, $this->availableLiquidity - $this->amountToInvest);
        $liquidityBufferRatio = min(1.0, $liquidityAfterInvestment / $monthlyExpenses / self::FULL_LIQUIDITY_BUFFER_MONTHS);

        $totalWealth = max($this->availableLiquidity + $this->netWorth, 1.0);
        $investedShareRatio = 1.0 - min(1.0, $this->amountToInvest / $totalWealth);

        $horizonRatio = min(1.0, $this->horizon->midpointYears() / self::FULL_RECOVERY_HORIZON_YEARS);

        // Somme de 4 ratios dans [0,1] : équivaut à leur moyenne rescalée sur [0,4], pour
        // rester homogène avec les autres dimensions (échelle de KnowledgeLevel).
        return $savingsRatio + $liquidityBufferRatio + $investedShareRatio + $horizonRatio;
    }
}
