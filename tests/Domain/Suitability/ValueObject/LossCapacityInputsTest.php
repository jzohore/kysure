<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\ValueObject\LossCapacityInputs;
use PHPUnit\Framework\TestCase;

final class LossCapacityInputsTest extends TestCase
{
    public function testScoreIsNeverNegativeNorAboveFour(): void
    {
        // Situation la plus défavorable possible : aucune épargne, aucune liquidité restante,
        // tout le disponible investi, horizon très court.
        $tight = LossCapacityInputs::fromInputs(
            annualIncome: 20000,
            annualExpenses: 20000,
            netWorth: 0,
            availableLiquidity: 5000,
            amountToInvest: 5000,
            horizon: InvestmentHorizon::MOINS_DE_3_ANS,
        );

        self::assertGreaterThanOrEqual(0.0, $tight->score());
        self::assertLessThanOrEqual(4.0, $tight->score());

        // Situation la plus favorable : forte épargne, gros coussin de liquidité, part investie
        // négligeable du patrimoine total, horizon long. Ni la part investie ni les charges ne
        // peuvent jamais atteindre 0 (contraintes strictement positives), donc le score
        // approche 4 sans jamais l'atteindre exactement.
        $comfortable = LossCapacityInputs::fromInputs(
            annualIncome: 100000,
            annualExpenses: 1,
            netWorth: 500000,
            availableLiquidity: 100000,
            amountToInvest: 10000,
            horizon: InvestmentHorizon::PLUS_DE_10_ANS,
        );

        self::assertGreaterThan(3.9, $comfortable->score());
        self::assertLessThanOrEqual(4.0, $comfortable->score());
    }

    public function testAComfortableSituationScoresHigherThanATightOne(): void
    {
        $tight = LossCapacityInputs::fromInputs(
            annualIncome: 25000,
            annualExpenses: 24000,
            netWorth: 0,
            availableLiquidity: 3000,
            amountToInvest: 3000,
            horizon: InvestmentHorizon::MOINS_DE_3_ANS,
        );

        $comfortable = LossCapacityInputs::fromInputs(
            annualIncome: 80000,
            annualExpenses: 30000,
            netWorth: 300000,
            availableLiquidity: 60000,
            amountToInvest: 10000,
            horizon: InvestmentHorizon::PLUS_DE_10_ANS,
        );

        self::assertGreaterThan($tight->score(), $comfortable->score());
    }

    public function testALongerHorizonIncreasesTheScoreAllElseEqual(): void
    {
        $base = static fn (InvestmentHorizon $horizon): LossCapacityInputs => LossCapacityInputs::fromInputs(
            annualIncome: 50000,
            annualExpenses: 30000,
            netWorth: 100000,
            availableLiquidity: 20000,
            amountToInvest: 10000,
            horizon: $horizon,
        );

        self::assertGreaterThan(
            $base(InvestmentHorizon::MOINS_DE_3_ANS)->score(),
            $base(InvestmentHorizon::PLUS_DE_10_ANS)->score(),
        );
    }

    public function testInvestingASmallerShareOfWealthIncreasesTheScoreAllElseEqual(): void
    {
        $withSmallInvestment = LossCapacityInputs::fromInputs(
            annualIncome: 50000,
            annualExpenses: 30000,
            netWorth: 100000,
            availableLiquidity: 20000,
            amountToInvest: 2000,
            horizon: InvestmentHorizon::DE_5_A_8_ANS,
        );

        $withLargeInvestment = LossCapacityInputs::fromInputs(
            annualIncome: 50000,
            annualExpenses: 30000,
            netWorth: 100000,
            availableLiquidity: 20000,
            amountToInvest: 18000,
            horizon: InvestmentHorizon::DE_5_A_8_ANS,
        );

        self::assertGreaterThan($withLargeInvestment->score(), $withSmallInvestment->score());
    }

    public function testRejectsAnAmountToInvestOfZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LossCapacityInputs::fromInputs(
            annualIncome: 50000,
            annualExpenses: 30000,
            netWorth: 100000,
            availableLiquidity: 20000,
            amountToInvest: 0,
            horizon: InvestmentHorizon::DE_5_A_8_ANS,
        );
    }
}
