<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

/**
 * Agrégat de toutes les réponses au questionnaire de profil investisseur (§3), tel que
 * soumis au moteur de notation. Ne porte aucune information sur l'auteur ou l'horodatage des
 * réponses : cette traçabilité est portée par l'entité de persistance, ajoutée au lot 1.
 */
final readonly class SuitabilityAssessmentInput
{
    private function __construct(
        public FinancialKnowledgeAnswers $knowledge,
        public ExperienceAnswers $experience,
        public RiskToleranceAnswers $tolerance,
        public LossCapacityInputs $capacity,
        public SustainabilityAnswers $sustainability,
    ) {
    }

    public static function fromAnswers(
        FinancialKnowledgeAnswers $knowledge,
        ExperienceAnswers $experience,
        RiskToleranceAnswers $tolerance,
        LossCapacityInputs $capacity,
        SustainabilityAnswers $sustainability,
    ): self {
        return new self($knowledge, $experience, $tolerance, $capacity, $sustainability);
    }
}
