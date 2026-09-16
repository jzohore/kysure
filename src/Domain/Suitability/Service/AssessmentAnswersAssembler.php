<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Service;

use App\Domain\Suitability\Enum\InvestmentHorizon;
use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Enum\SustainabilityPreference;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;
use App\Domain\Suitability\ValueObject\LossCapacityInputs;
use App\Domain\Suitability\ValueObject\RiskToleranceAnswers;
use App\Domain\Suitability\ValueObject\SuitabilityAssessmentInput;
use App\Domain\Suitability\ValueObject\SustainabilityAnswers;

/**
 * Reconstruit un {@see SuitabilityAssessmentInput} typé à partir des réponses brutes stockées
 * sur un {@see \App\Domain\Suitability\Entity\InvestorProfileAssessment} (une valeur par
 * {@see QuestionKey}, telle que décodée du JSON — scalaires PHP natifs).
 *
 * N'assemble pas depuis l'entité directement : prend un `array<string, mixed>` déjà extrait,
 * pour rester testable sans construire un assessment complet.
 */
final readonly class AssessmentAnswersAssembler
{
    /**
     * @param array<string, mixed> $answersByKey clé = QuestionKey::value
     */
    public function assemble(array $answersByKey): SuitabilityAssessmentInput
    {
        return SuitabilityAssessmentInput::fromAnswers(
            knowledge: $this->buildKnowledge($answersByKey),
            experience: $this->buildExperience($answersByKey),
            tolerance: $this->buildTolerance($answersByKey),
            capacity: $this->buildCapacity($answersByKey),
            sustainability: $this->buildSustainability($answersByKey),
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function buildKnowledge(array $answers): FinancialKnowledgeAnswers
    {
        return FinancialKnowledgeAnswers::fromLevels([
            ProductFamily::OPCVM_ETF->value => KnowledgeLevel::from($this->requireInt($answers, QuestionKey::KNOWLEDGE_OPCVM_ETF)),
            ProductFamily::TITRES_VIFS->value => KnowledgeLevel::from($this->requireInt($answers, QuestionKey::KNOWLEDGE_TITRES_VIFS)),
            ProductFamily::ASSURANCE_VIE->value => KnowledgeLevel::from($this->requireInt($answers, QuestionKey::KNOWLEDGE_ASSURANCE_VIE)),
            ProductFamily::IMMOBILIER_SCPI->value => KnowledgeLevel::from($this->requireInt($answers, QuestionKey::KNOWLEDGE_IMMOBILIER_SCPI)),
            ProductFamily::PRODUITS_COMPLEXES->value => KnowledgeLevel::from($this->requireInt($answers, QuestionKey::KNOWLEDGE_PRODUITS_COMPLEXES)),
        ]);
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function buildExperience(array $answers): ExperienceAnswers
    {
        $rawProductsHeld = $answers[QuestionKey::EXPERIENCE_PRODUCTS_HELD->value] ?? null;
        if (!\is_array($rawProductsHeld)) {
            throw new \DomainException(sprintf('Réponse "%s" manquante ou invalide.', QuestionKey::EXPERIENCE_PRODUCTS_HELD->value));
        }

        return ExperienceAnswers::fromAnswers(
            productsHeld: array_map(
                static fn (mixed $value): ProductFamily => ProductFamily::from((string) $value),
                array_values($rawProductsHeld),
            ),
            transactionFrequency: TransactionFrequency::from($this->requireInt($answers, QuestionKey::EXPERIENCE_TRANSACTION_FREQUENCY)),
            approximateAmount: $this->requireFloat($answers, QuestionKey::EXPERIENCE_APPROXIMATE_AMOUNT),
            experienceYears: $this->requireInt($answers, QuestionKey::EXPERIENCE_YEARS),
            approximateTransactionCount: $this->requireInt($answers, QuestionKey::EXPERIENCE_TRANSACTION_COUNT),
            hasExperiencedLosses: (bool) ($answers[QuestionKey::EXPERIENCE_HAS_EXPERIENCED_LOSSES->value] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function buildTolerance(array $answers): RiskToleranceAnswers
    {
        return RiskToleranceAnswers::fromReactions(
            reactionToMinus10Percent: LossReaction::from($this->requireInt($answers, QuestionKey::TOLERANCE_REACTION_MINUS_10)),
            reactionToMinus20Percent: LossReaction::from($this->requireInt($answers, QuestionKey::TOLERANCE_REACTION_MINUS_20)),
            reactionToSignificantLoss: LossReaction::from($this->requireInt($answers, QuestionKey::TOLERANCE_REACTION_SIGNIFICANT_LOSS)),
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function buildCapacity(array $answers): LossCapacityInputs
    {
        return LossCapacityInputs::fromInputs(
            annualIncome: $this->requireFloat($answers, QuestionKey::CAPACITY_ANNUAL_INCOME),
            annualExpenses: $this->requireFloat($answers, QuestionKey::CAPACITY_ANNUAL_EXPENSES),
            netWorth: $this->requireFloat($answers, QuestionKey::CAPACITY_NET_WORTH),
            availableLiquidity: $this->requireFloat($answers, QuestionKey::CAPACITY_AVAILABLE_LIQUIDITY),
            amountToInvest: $this->requireFloat($answers, QuestionKey::CAPACITY_AMOUNT_TO_INVEST),
            horizon: InvestmentHorizon::from($this->requireString($answers, QuestionKey::CAPACITY_HORIZON)),
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function buildSustainability(array $answers): SustainabilityAnswers
    {
        $constraints = $answers[QuestionKey::SUSTAINABILITY_CONSTRAINTS->value] ?? null;

        return SustainabilityAnswers::fromAnswers(
            preference: SustainabilityPreference::from($this->requireString($answers, QuestionKey::SUSTAINABILITY_PREFERENCE)),
            specificConstraints: null !== $constraints ? (string) $constraints : null,
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function requireInt(array $answers, QuestionKey $key): int
    {
        $value = $answers[$key->value] ?? null;
        if (!\is_int($value)) {
            throw new \DomainException(sprintf('Réponse "%s" manquante ou invalide (nombre entier attendu).', $key->value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function requireFloat(array $answers, QuestionKey $key): float
    {
        $value = $answers[$key->value] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \DomainException(sprintf('Réponse "%s" manquante ou invalide (nombre attendu).', $key->value));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function requireString(array $answers, QuestionKey $key): string
    {
        $value = $answers[$key->value] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \DomainException(sprintf('Réponse "%s" manquante ou invalide (chaîne attendue).', $key->value));
        }

        return $value;
    }
}
