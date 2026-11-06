<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport\Service;

use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;
use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\AdequacyReport\ValueObject\CostBreakdown;
use App\Domain\AdequacyReport\ValueObject\ProductCharacteristics;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CostBreakdownCalculatorTest extends TestCase
{
    public function testBreaksDownProductFeesAndCifRemunerationOverTheProductHorizon(): void
    {
        // 20 000 €, droits d'entrée 2 %, honoraires 500 €, rétrocessions 0,10 %/an, produit 0,30 %/an sur 5 ans.
        $costs = $this->calculate(
            amountInCents: 2_000_000,
            annualFeesBasisPoints: 30,
            horizonYears: 5,
            remuneration: new CifRemuneration(entryFeeBasisPoints: 200, advisoryFeeInCents: 50_000, annualRetrocessionBasisPoints: 10),
        );

        self::assertSame(CostBreakdownCalculator::VERSION, $costs->calculatorVersion);
        self::assertSame(5, $costs->horizonYears);
        self::assertSame(40_000, $costs->entryFeeInCents);
        self::assertSame(1_960_000, $costs->netInvestedInCents());
        self::assertSame(5_880, $costs->annualFeesInCents, 'Frais annuels sur le montant net des droits d\'entrée.');
        self::assertSame(29_400, $costs->cumulativeAnnualFeesInCents);
        self::assertSame(1_960, $costs->annualRetrocessionInCents);
        self::assertSame(9_800, $costs->cumulativeRetrocessionInCents);
        self::assertSame(119_400, $costs->totalCostsInCents, 'Droits d\'entrée + honoraires + frais cumulés, rétrocessions non ajoutées.');
        self::assertSame(597, $costs->totalCostsBasisPoints);
        self::assertSame(99_800, $costs->cifRemunerationOverHorizonInCents());
    }

    public function testWithoutCifRemunerationOnlyProductFeesRemain(): void
    {
        $costs = $this->calculate(amountInCents: 1_000_000, annualFeesBasisPoints: 120, horizonYears: 8, remuneration: CifRemuneration::none());

        self::assertSame(0, $costs->entryFeeInCents);
        self::assertSame(12_000, $costs->annualFeesInCents);
        self::assertSame(96_000, $costs->cumulativeAnnualFeesInCents);
        self::assertSame(96_000, $costs->totalCostsInCents);
        self::assertSame(960, $costs->totalCostsBasisPoints);
        self::assertSame(0, $costs->cifRemunerationOverHorizonInCents());
    }

    public function testProductWithoutMinimumHorizonIsCountedOverOneYear(): void
    {
        $costs = $this->calculate(amountInCents: 1_000_000, annualFeesBasisPoints: 50, horizonYears: 0, remuneration: CifRemuneration::none());

        self::assertSame(1, $costs->horizonYears);
        self::assertSame(5_000, $costs->cumulativeAnnualFeesInCents);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function roundingCases(): iterable
    {
        yield '0,999 centime arrondi à 1' => [333, 30, 1];
        yield '1,5 centime arrondi à 2 (demi vers le haut)' => [150, 100, 2];
        yield '1,49 centime arrondi à 1' => [149, 100, 1];
        yield 'taux nul' => [1_000_000, 0, 0];
    }

    #[DataProvider('roundingCases')]
    public function testRoundsEachAmountToTheNearestCent(int $amountInCents, int $annualFeesBasisPoints, int $expectedAnnualFees): void
    {
        $costs = $this->calculate($amountInCents, $annualFeesBasisPoints, 1, CifRemuneration::none());

        self::assertSame($expectedAnnualFees, $costs->annualFeesInCents);
    }

    public function testRetrocessionsCannotExceedTheProductFees(): void
    {
        $this->expectException(\DomainException::class);

        $this->calculate(1_000_000, 30, 5, new CifRemuneration(annualRetrocessionBasisPoints: 31));
    }

    public function testFrozenBreakdownIsReadBackWithoutRecomputation(): void
    {
        $costs = $this->calculate(2_000_000, 30, 5, new CifRemuneration(200, 50_000, 10));

        self::assertEquals($costs, CostBreakdown::fromArray($costs->toArray()));
    }

    private function calculate(int $amountInCents, int $annualFeesBasisPoints, int $horizonYears, CifRemuneration $remuneration): CostBreakdown
    {
        $product = new ProductCharacteristics(
            name: 'ETF MSCI World',
            isin: null,
            family: ProductFamily::OPCVM_ETF,
            sriLevel: 4,
            minimumHorizonYears: $horizonYears,
            annualFeesBasisPoints: $annualFeesBasisPoints,
            targetInvestorProfiles: [InvestorProfileLevel::EQUILIBRE],
            sfdrClassification: null,
        );

        return new CostBreakdownCalculator()->calculate($product, $amountInCents, $remuneration);
    }
}
