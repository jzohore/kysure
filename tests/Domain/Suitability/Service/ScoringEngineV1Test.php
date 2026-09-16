<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Service;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\Service\ScoringEngineV1;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;
use App\Domain\Suitability\ValueObject\LossCapacityInputs;
use App\Domain\Suitability\ValueObject\RiskToleranceAnswers;
use App\Domain\Suitability\ValueObject\SuitabilityAssessmentInput;
use App\Domain\Suitability\ValueObject\SustainabilityAnswers;
use PHPUnit\Framework\TestCase;

final class ScoringEngineV1Test extends TestCase
{
    private ScoringEngineV1 $engine;

    protected function setUp(): void
    {
        $this->engine = new ScoringEngineV1();
    }

    public function testVersionIsStableAndExplicit(): void
    {
        self::assertSame('suitability_engine_v1', $this->engine->version());
    }

    public function testAClientWithMinimalKnowledgeExperienceAndToleranceGetsTheMostPrudentProfile(): void
    {
        $input = $this->buildInput(
            knowledgeLevel: KnowledgeLevel::AUCUNE,
            productsHeld: [],
            transactionFrequency: TransactionFrequency::JAMAIS,
            experienceYears: 0,
            hasExperiencedLosses: false,
            reaction: LossReaction::VEND_TOUT,
            capacity: $this->comfortableCapacity(), // capacité confortable : ne doit pas plafonner ici
        );

        $result = $this->engine->score($input);

        self::assertSame(InvestorProfileLevel::TRES_PRUDENT, $result->finalProfile);
        self::assertFalse($result->cappedByCapacity);
    }

    public function testAClientWithMaximalKnowledgeExperienceAndToleranceGetsTheMostAggressiveProfileWhenCapacityAllowsIt(): void
    {
        $input = $this->buildInput(
            knowledgeLevel: KnowledgeLevel::EXPERTISE,
            productsHeld: ProductFamily::cases(),
            transactionFrequency: TransactionFrequency::FREQUENTE,
            experienceYears: 20,
            hasExperiencedLosses: true,
            reaction: LossReaction::RENFORCE_LA_POSITION,
            capacity: $this->comfortableCapacity(),
        );

        $result = $this->engine->score($input);

        self::assertSame(InvestorProfileLevel::AGRESSIF, $result->finalProfile);
        self::assertFalse($result->cappedByCapacity);
    }

    public function testAHighAppetiteIsCappedByAWeakCapacityToSubLosses(): void
    {
        $input = $this->buildInput(
            knowledgeLevel: KnowledgeLevel::EXPERTISE,
            productsHeld: ProductFamily::cases(),
            transactionFrequency: TransactionFrequency::FREQUENTE,
            experienceYears: 20,
            hasExperiencedLosses: true,
            reaction: LossReaction::RENFORCE_LA_POSITION,
            capacity: $this->tightCapacity(),
        );

        $result = $this->engine->score($input);

        self::assertTrue($result->cappedByCapacity);
        self::assertSame($result->capacityLevel, $result->finalProfile);
        self::assertLessThan($result->rawProfile->value, $result->finalProfile->value);
        self::assertStringContainsString('plafonné', implode(' ', $result->explanationFactors));
    }

    public function testTheCapacityNeverCapsAProfileThatIsAlreadyLowerOrEqual(): void
    {
        $input = $this->buildInput(
            knowledgeLevel: KnowledgeLevel::AUCUNE,
            productsHeld: [],
            transactionFrequency: TransactionFrequency::JAMAIS,
            experienceYears: 0,
            hasExperiencedLosses: false,
            reaction: LossReaction::VEND_TOUT,
            capacity: $this->tightCapacity(),
        );

        $result = $this->engine->score($input);

        self::assertFalse($result->cappedByCapacity);
        self::assertSame($result->rawProfile, $result->finalProfile);
    }

    public function testExplanationFactorsAlwaysMentionTheFourDimensionsAndTheSustainabilityPreference(): void
    {
        $input = $this->buildInput(
            knowledgeLevel: KnowledgeLevel::BONNE,
            productsHeld: [ProductFamily::OPCVM_ETF],
            transactionFrequency: TransactionFrequency::REGULIERE,
            experienceYears: 5,
            hasExperiencedLosses: false,
            reaction: LossReaction::NE_FAIT_RIEN,
            capacity: $this->comfortableCapacity(),
        );

        $factors = implode(' | ', $this->engine->score($input)->explanationFactors);

        self::assertStringContainsString('Connaissances financières', $factors);
        self::assertStringContainsString('Expérience d\'investissement', $factors);
        self::assertStringContainsString('Tolérance au risque', $factors);
        self::assertStringContainsString('Capacité à subir des pertes', $factors);
        self::assertStringContainsString('durabilité', $factors);
    }

    private function comfortableCapacity(): LossCapacityInputs
    {
        return LossCapacityInputs::fromInputs(
            annualIncome: 100000,
            annualExpenses: 20000,
            netWorth: 500000,
            availableLiquidity: 100000,
            amountToInvest: 10000,
            horizon: InvestmentHorizon::PLUS_DE_10_ANS,
        );
    }

    private function tightCapacity(): LossCapacityInputs
    {
        return LossCapacityInputs::fromInputs(
            annualIncome: 22000,
            annualExpenses: 21000,
            netWorth: 0,
            availableLiquidity: 4000,
            amountToInvest: 4000,
            horizon: InvestmentHorizon::MOINS_DE_3_ANS,
        );
    }

    /**
     * @param list<ProductFamily> $productsHeld
     */
    private function buildInput(
        KnowledgeLevel $knowledgeLevel,
        array $productsHeld,
        TransactionFrequency $transactionFrequency,
        int $experienceYears,
        bool $hasExperiencedLosses,
        LossReaction $reaction,
        LossCapacityInputs $capacity,
    ): SuitabilityAssessmentInput {
        $levels = [];
        foreach (ProductFamily::cases() as $family) {
            $levels[$family->value] = $knowledgeLevel;
        }

        return SuitabilityAssessmentInput::fromAnswers(
            knowledge: FinancialKnowledgeAnswers::fromLevels($levels),
            experience: ExperienceAnswers::fromAnswers(
                productsHeld: $productsHeld,
                transactionFrequency: $transactionFrequency,
                approximateAmount: 1000.0,
                experienceYears: $experienceYears,
                approximateTransactionCount: 10,
                hasExperiencedLosses: $hasExperiencedLosses,
            ),
            tolerance: RiskToleranceAnswers::fromReactions($reaction, $reaction, $reaction),
            capacity: $capacity,
            sustainability: SustainabilityAnswers::fromAnswers(SustainabilityPreference::SANS_PREFERENCE),
        );
    }
}
