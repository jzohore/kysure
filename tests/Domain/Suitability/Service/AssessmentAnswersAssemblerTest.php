<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Service;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\Service\AssessmentAnswersAssembler;
use App\Domain\Suitability\Service\ScoringEngineV1;
use PHPUnit\Framework\TestCase;

final class AssessmentAnswersAssemblerTest extends TestCase
{
    private AssessmentAnswersAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new AssessmentAnswersAssembler();
    }

    public function testAssemblesACompleteAnswerSetIntoAScorableInput(): void
    {
        $input = $this->assembler->assemble($this->completeAnswers());

        // Round-trip : le résultat doit être directement exploitable par le moteur, sans
        // qu'aucune valeur ne déclenche une erreur de type ou une assertion de domaine.
        $result = new ScoringEngineV1()->score($input);

        self::assertSame('suitability_engine_v1', $result->engineVersion);
    }

    public function testThrowsADomainExceptionWhenAnAnswerIsMissing(): void
    {
        $answers = $this->completeAnswers();
        unset($answers[QuestionKey::CAPACITY_ANNUAL_INCOME->value]);

        $this->expectException(\DomainException::class);

        $this->assembler->assemble($answers);
    }

    public function testThrowsADomainExceptionWhenAnAnswerHasTheWrongType(): void
    {
        $answers = $this->completeAnswers();
        $answers[QuestionKey::KNOWLEDGE_OPCVM_ETF->value] = 'bonne'; // attendu : entier (KnowledgeLevel)

        $this->expectException(\DomainException::class);

        $this->assembler->assemble($answers);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeAnswers(): array
    {
        return [
            QuestionKey::KNOWLEDGE_OPCVM_ETF->value => KnowledgeLevel::BONNE->value,
            QuestionKey::KNOWLEDGE_TITRES_VIFS->value => KnowledgeLevel::LIMITEE->value,
            QuestionKey::KNOWLEDGE_ASSURANCE_VIE->value => KnowledgeLevel::EXPERTISE->value,
            QuestionKey::KNOWLEDGE_IMMOBILIER_SCPI->value => KnowledgeLevel::INTERMEDIAIRE->value,
            QuestionKey::KNOWLEDGE_PRODUITS_COMPLEXES->value => KnowledgeLevel::AUCUNE->value,

            QuestionKey::EXPERIENCE_PRODUCTS_HELD->value => [ProductFamily::OPCVM_ETF->value, ProductFamily::ASSURANCE_VIE->value],
            QuestionKey::EXPERIENCE_TRANSACTION_FREQUENCY->value => TransactionFrequency::REGULIERE->value,
            QuestionKey::EXPERIENCE_APPROXIMATE_AMOUNT->value => 50000,
            QuestionKey::EXPERIENCE_YEARS->value => 8,
            QuestionKey::EXPERIENCE_TRANSACTION_COUNT->value => 40,
            QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value => true,

            QuestionKey::TOLERANCE_REACTION_MINUS_10->value => LossReaction::NE_FAIT_RIEN->value,
            QuestionKey::TOLERANCE_REACTION_MINUS_20->value => LossReaction::REDUIT_LA_POSITION->value,
            QuestionKey::TOLERANCE_REACTION_SIGNIFICANT_LOSS->value => LossReaction::VEND_TOUT->value,

            QuestionKey::CAPACITY_ANNUAL_INCOME->value => 60000,
            QuestionKey::CAPACITY_ANNUAL_EXPENSES->value => 35000,
            QuestionKey::CAPACITY_NET_WORTH->value => 250000,
            QuestionKey::CAPACITY_AVAILABLE_LIQUIDITY->value => 40000,
            QuestionKey::CAPACITY_AMOUNT_TO_INVEST->value => 20000,
            QuestionKey::CAPACITY_HORIZON->value => InvestmentHorizon::DE_5_A_8_ANS->value,

            QuestionKey::SUSTAINABILITY_PREFERENCE->value => SustainabilityPreference::SANS_PREFERENCE->value,
            QuestionKey::SUSTAINABILITY_CONSTRAINTS->value => null,
        ];
    }
}
