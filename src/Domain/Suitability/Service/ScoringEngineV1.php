<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Service;

use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\ValueObject\SuitabilityAssessmentInput;
use App\Domain\Suitability\ValueObject\SuitabilityScoreResult;

/**
 * Première version du moteur de notation. Les pondérations ci-dessous sont un point de
 * départ à calibrer avec le CGP (lot 0, issue #23) sur des cas types réels — c'est
 * précisément l'objet de ce lot : ne pas les considérer comme définitives.
 *
 * La tolérance au risque (comportementale) pèse plus lourd que les connaissances et
 * l'expérience, qui évaluent la capacité du client à *comprendre* un produit plutôt que le
 * niveau de risque qu'il souhaite prendre.
 */
final readonly class ScoringEngineV1 implements SuitabilityScoringEngineInterface
{
    private const string VERSION = 'suitability_engine_v1';

    private const float KNOWLEDGE_WEIGHT = 0.2;
    private const float EXPERIENCE_WEIGHT = 0.2;
    private const float TOLERANCE_WEIGHT = 0.6;

    /**
     * Convertit un score brut sur l'échelle 0-4 (KnowledgeLevel) vers l'échelle 1-7
     * (InvestorProfileLevel) : 0 → 1, 4 → 7.
     */
    private const float RAW_TO_PROFILE_SCALE_FACTOR = 6 / 4;

    /**
     * Convertit un score brut sur l'échelle 0-3 (LossReaction) vers l'échelle 1-7.
     */
    private const float TOLERANCE_TO_PROFILE_SCALE_FACTOR = 6 / 3;

    public function version(): string
    {
        return self::VERSION;
    }

    public function score(SuitabilityAssessmentInput $input): SuitabilityScoreResult
    {
        $knowledgeScore = 1 + $input->knowledge->averageScore() * self::RAW_TO_PROFILE_SCALE_FACTOR;
        $experienceScore = 1 + $input->experience->score() * self::RAW_TO_PROFILE_SCALE_FACTOR;
        $toleranceScore = 1 + $input->tolerance->averageScore() * self::TOLERANCE_TO_PROFILE_SCALE_FACTOR;
        $capacityScore = 1 + $input->capacity->score() * self::RAW_TO_PROFILE_SCALE_FACTOR;

        $capacityLevel = InvestorProfileLevel::fromScore($capacityScore);

        $rawScore = $knowledgeScore * self::KNOWLEDGE_WEIGHT
            + $experienceScore * self::EXPERIENCE_WEIGHT
            + $toleranceScore * self::TOLERANCE_WEIGHT;
        $rawProfile = InvestorProfileLevel::fromScore($rawScore);

        return SuitabilityScoreResult::build(
            engineVersion: self::VERSION,
            knowledgeScore: $knowledgeScore,
            experienceScore: $experienceScore,
            toleranceScore: $toleranceScore,
            capacityLevel: $capacityLevel,
            rawProfile: $rawProfile,
            explanationFactors: $this->buildExplanationFactors(
                $input,
                $knowledgeScore,
                $experienceScore,
                $toleranceScore,
                $capacityLevel,
                $rawProfile,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function buildExplanationFactors(
        SuitabilityAssessmentInput $input,
        float $knowledgeScore,
        float $experienceScore,
        float $toleranceScore,
        InvestorProfileLevel $capacityLevel,
        InvestorProfileLevel $rawProfile,
    ): array {
        $factors = [
            sprintf('Connaissances financières : %.1f/7', $knowledgeScore),
            sprintf('Expérience d\'investissement : %.1f/7', $experienceScore),
            sprintf('Tolérance au risque (réactions aux scénarios de perte) : %.1f/7', $toleranceScore),
            sprintf('Capacité à subir des pertes : %s (%s)', $capacityLevel->getLabel(), $capacityLevel->value),
            sprintf('Préférence de durabilité déclarée : %s', $input->sustainability->preference->getLabel()),
        ];

        if ($capacityLevel->value < $rawProfile->value) {
            $factors[] = sprintf(
                'Profil plafonné par la capacité à subir des pertes : %s (%d) retenu au lieu de %s (%d) sur la seule appétence.',
                $capacityLevel->getLabel(),
                $capacityLevel->value,
                $rawProfile->getLabel(),
                $rawProfile->value,
            );
        }

        return $factors;
    }
}
