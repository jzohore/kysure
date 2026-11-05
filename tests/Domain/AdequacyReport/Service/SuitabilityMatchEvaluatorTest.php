<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport\Service;

use App\Domain\AdequacyReport\Enum\MatchCriterion;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\AdequacyCheckInput;
use App\Domain\AdequacyReport\ValueObject\MatchFinding;
use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ProductCatalogue\Enum\SfdrClassification;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Tests\Domain\AdequacyReport\AdequacyCheckInputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SuitabilityMatchEvaluatorTest extends TestCase
{
    private SuitabilityMatchEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new SuitabilityMatchEvaluator();
    }

    public function testReferenceScenarioIsFullyCompliantWithOneFindingPerCriterion(): void
    {
        $evaluation = $this->evaluator->evaluate(AdequacyCheckInputFactory::create());

        self::assertSame(MatchCriterion::cases(), array_map(static fn (MatchFinding $f): MatchCriterion => $f->criterion, $evaluation->findings));
        self::assertSame(MatchSeverity::CONFORME, $evaluation->worstSeverity());
        self::assertFalse($evaluation->hasBlockingFinding());
    }

    /**
     * @return iterable<string, array{int, MatchSeverity}>
     */
    public static function riskLevelProvider(): iterable
    {
        yield 'SRI sous le profil' => [3, MatchSeverity::CONFORME];
        yield 'SRI égal au profil' => [4, MatchSeverity::CONFORME];
        yield 'SRI un cran au-dessus' => [5, MatchSeverity::ATTENTION];
        yield 'SRI deux crans au-dessus' => [6, MatchSeverity::BLOQUANT];
    }

    #[DataProvider('riskLevelProvider')]
    public function testRiskLevelComparesSriWithRetainedProfile(int $sri, MatchSeverity $expected): void
    {
        $input = AdequacyCheckInputFactory::create(sriLevel: $sri);

        self::assertSame($expected, $this->finding($input, MatchCriterion::RISK_LEVEL)->severity);
    }

    public function testProfileOutsideTargetMarketIsAnAttentionPoint(): void
    {
        $input = AdequacyCheckInputFactory::create(targetProfiles: [InvestorProfileLevel::DYNAMIQUE]);

        self::assertSame(MatchSeverity::ATTENTION, $this->finding($input, MatchCriterion::TARGET_MARKET)->severity);
    }

    /**
     * @return iterable<string, array{int, InvestmentHorizon, MatchSeverity}>
     */
    public static function horizonProvider(): iterable
    {
        yield 'produit 5 ans, objectif 5-8 ans' => [5, InvestmentHorizon::FROM_5_TO_8_YEARS, MatchSeverity::CONFORME];
        yield 'produit 6 ans, objectif 5-8 ans' => [6, InvestmentHorizon::FROM_5_TO_8_YEARS, MatchSeverity::ATTENTION];
        yield 'produit 8 ans, objectif 3-5 ans' => [8, InvestmentHorizon::FROM_3_TO_5_YEARS, MatchSeverity::BLOQUANT];
        yield 'produit 15 ans, objectif plus de 10 ans' => [15, InvestmentHorizon::OVER_10_YEARS, MatchSeverity::ATTENTION];
    }

    #[DataProvider('horizonProvider')]
    public function testHorizonComparesProductMinimumWithObjectiveBracket(int $productYears, InvestmentHorizon $horizon, MatchSeverity $expected): void
    {
        $input = AdequacyCheckInputFactory::create(productHorizonYears: $productYears, objectiveHorizon: $horizon);

        self::assertSame($expected, $this->finding($input, MatchCriterion::HORIZON)->severity);
    }

    public function testComplexProductWithLimitedKnowledgeIsBlocking(): void
    {
        $input = AdequacyCheckInputFactory::create(family: ProductFamily::PRODUITS_COMPLEXES, knowledge: KnowledgeLevel::LIMITEE);

        self::assertSame(MatchSeverity::BLOQUANT, $this->finding($input, MatchCriterion::KNOWLEDGE_EXPERIENCE)->severity);
    }

    public function testComplexProductNeverHeldIsAnAttentionPoint(): void
    {
        $input = AdequacyCheckInputFactory::create(family: ProductFamily::PRODUITS_COMPLEXES, knowledge: KnowledgeLevel::INTERMEDIAIRE, productsHeld: []);

        self::assertSame(MatchSeverity::ATTENTION, $this->finding($input, MatchCriterion::KNOWLEDGE_EXPERIENCE)->severity);
    }

    public function testUnknownSimpleFamilyIsOnlyAnAttentionPoint(): void
    {
        $input = AdequacyCheckInputFactory::create(knowledge: KnowledgeLevel::AUCUNE, productsHeld: []);

        self::assertSame(MatchSeverity::ATTENTION, $this->finding($input, MatchCriterion::KNOWLEDGE_EXPERIENCE)->severity);
    }

    public function testAmountAboveInvestmentCapacityIsBlocking(): void
    {
        $input = AdequacyCheckInputFactory::create(capacityInCents: 1_000_000, amountInCents: 1_500_000);

        self::assertSame(MatchSeverity::BLOQUANT, $this->finding($input, MatchCriterion::INVESTMENT_CAPACITY)->severity);
    }

    /**
     * Patrimoine net de référence : 300 000 + 50 000 + 30 000 − 100 000 = 280 000 €.
     *
     * @return iterable<string, array{int, MatchSeverity}>
     */
    public static function concentrationProvider(): iterable
    {
        yield '25 % pile' => [7_000_000, MatchSeverity::CONFORME];
        yield '26 %' => [7_280_000, MatchSeverity::ATTENTION];
        yield '50 % pile' => [14_000_000, MatchSeverity::ATTENTION];
        yield 'un centime au-delà de 50 %' => [14_000_001, MatchSeverity::BLOQUANT];
    }

    #[DataProvider('concentrationProvider')]
    public function testConcentrationThresholds(int $amountInCents, MatchSeverity $expected): void
    {
        $input = AdequacyCheckInputFactory::create(capacityInCents: 50_000_000, amountInCents: $amountInCents);

        self::assertSame($expected, $this->finding($input, MatchCriterion::CONCENTRATION)->severity);
    }

    public function testNonPositiveNetWorthIsBlocking(): void
    {
        $input = AdequacyCheckInputFactory::create(debtInCents: 40_000_000);

        self::assertSame(MatchSeverity::BLOQUANT, $this->finding($input, MatchCriterion::CONCENTRATION)->severity);
    }

    public function testLowResidualLiquidityIsAnAttentionPointForALiquidProduct(): void
    {
        // 80 000 € d'épargne disponible − 70 000 € investis = 10 000 €, sous 6 mois de charges (20 000 €).
        $input = AdequacyCheckInputFactory::create(capacityInCents: 10_000_000, amountInCents: 7_000_000);

        self::assertSame(MatchSeverity::ATTENTION, $this->finding($input, MatchCriterion::LIQUIDITY)->severity);
    }

    public function testLowResidualLiquidityIsBlockingForAnIlliquidProduct(): void
    {
        $input = AdequacyCheckInputFactory::create(family: ProductFamily::IMMOBILIER_SCPI, productsHeld: [ProductFamily::IMMOBILIER_SCPI], capacityInCents: 10_000_000, amountInCents: 7_000_000);

        self::assertSame(MatchSeverity::BLOQUANT, $this->finding($input, MatchCriterion::LIQUIDITY)->severity);
    }

    /**
     * @return iterable<string, array{SustainabilityPreference, ?SfdrClassification, MatchSeverity}>
     */
    public static function sustainabilityProvider(): iterable
    {
        yield 'pas de préférence, article 6' => [SustainabilityPreference::NON_INTERESSE, SfdrClassification::ARTICLE_6, MatchSeverity::CONFORME];
        yield 'intéressé, article 9' => [SustainabilityPreference::INTERESSE, SfdrClassification::ARTICLE_9, MatchSeverity::CONFORME];
        yield 'intéressé, article 6' => [SustainabilityPreference::INTERESSE, SfdrClassification::ARTICLE_6, MatchSeverity::ATTENTION];
        yield 'intéressé, classification inconnue' => [SustainabilityPreference::INTERESSE, null, MatchSeverity::ATTENTION];
    }

    #[DataProvider('sustainabilityProvider')]
    public function testSustainabilityPreferenceIsNeverBlocking(SustainabilityPreference $preference, ?SfdrClassification $sfdr, MatchSeverity $expected): void
    {
        $input = AdequacyCheckInputFactory::create(sfdr: $sfdr, sustainability: $preference);

        self::assertSame($expected, $this->finding($input, MatchCriterion::SUSTAINABILITY)->severity);
    }

    public function testWorstSeverityReflectsTheMostSevereFinding(): void
    {
        $evaluation = $this->evaluator->evaluate(AdequacyCheckInputFactory::create(sriLevel: 5, capacityInCents: 1_000_000));

        self::assertSame(MatchSeverity::BLOQUANT, $evaluation->worstSeverity());
        self::assertSame(1, $evaluation->countBySeverity(MatchSeverity::BLOQUANT));
        self::assertSame(1, $evaluation->countBySeverity(MatchSeverity::ATTENTION));
    }

    private function finding(AdequacyCheckInput $input, MatchCriterion $criterion): MatchFinding
    {
        foreach ($this->evaluator->evaluate($input)->findings as $finding) {
            if ($finding->criterion === $criterion) {
                return $finding;
            }
        }

        self::fail(sprintf('Aucun constat pour le contrôle %s.', $criterion->value));
    }
}
