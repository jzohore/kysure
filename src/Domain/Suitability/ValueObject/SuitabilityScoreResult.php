<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\InvestorProfileLevel;
use Webmozart\Assert\Assert;

/**
 * Résultat produit par un moteur de notation (§3.6) : score par dimension, profil brut
 * (avant plafonnement), profil final (après plafonnement par la capacité à subir des
 * pertes), et facteurs explicatifs à restituer au client et au CGP.
 */
final readonly class SuitabilityScoreResult
{
    /**
     * @param list<string> $explanationFactors
     */
    private function __construct(
        public string $engineVersion,
        public float $knowledgeScore,
        public float $experienceScore,
        public float $toleranceScore,
        public InvestorProfileLevel $capacityLevel,
        public InvestorProfileLevel $rawProfile,
        public InvestorProfileLevel $finalProfile,
        public bool $cappedByCapacity,
        public array $explanationFactors,
    ) {
        Assert::allString($this->explanationFactors, 'Chaque facteur explicatif doit être une chaîne de caractères.');
    }

    /**
     * @param list<string> $explanationFactors
     */
    public static function build(
        string $engineVersion,
        float $knowledgeScore,
        float $experienceScore,
        float $toleranceScore,
        InvestorProfileLevel $capacityLevel,
        InvestorProfileLevel $rawProfile,
        array $explanationFactors,
    ): self {
        $finalProfile = $rawProfile->value <= $capacityLevel->value ? $rawProfile : $capacityLevel;

        return new self(
            engineVersion: $engineVersion,
            knowledgeScore: $knowledgeScore,
            experienceScore: $experienceScore,
            toleranceScore: $toleranceScore,
            capacityLevel: $capacityLevel,
            rawProfile: $rawProfile,
            finalProfile: $finalProfile,
            cappedByCapacity: $finalProfile !== $rawProfile,
            explanationFactors: $explanationFactors,
        );
    }
}
