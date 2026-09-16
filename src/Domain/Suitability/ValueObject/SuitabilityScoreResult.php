<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\InvestorProfileLevel;
use Webmozart\Assert\Assert;

/**
 * Résultat produit par un moteur de notation (§3.6) : score par dimension, profil brut (avant
 * plafonnement), profil final, et facteurs explicatifs à restituer au client et au CGP.
 *
 * Le profil final ne peut jamais dépasser la capacité à subir des pertes, ni excéder de plus
 * d'un cran la tolérance au risque déclarée (audit conformité du lot 0, issue #23) : les
 * connaissances/expérience ne doivent jamais pouvoir compenser une tolérance ou une capacité
 * faibles, elles ne font que moduler le profil *dans la limite* de ce que ces deux dimensions
 * autorisent.
 */
final readonly class SuitabilityScoreResult
{
    /**
     * @param array{knowledge: float, experience: float, tolerance: float} $appliedWeights     pondérations utilisées pour ce calcul, conservées pour que le dossier reste auto-portant même si le moteur évolue
     * @param list<string>                                                 $explanationFactors
     */
    private function __construct(
        public string $engineVersion,
        public float $knowledgeScore,
        public float $experienceScore,
        public float $toleranceScore,
        public InvestorProfileLevel $capacityLevel,
        public InvestorProfileLevel $toleranceLevel,
        public InvestorProfileLevel $rawProfile,
        public InvestorProfileLevel $finalProfile,
        public bool $cappedByCapacity,
        public bool $cappedByTolerance,
        public array $appliedWeights,
        public float $rawScore,
        public array $explanationFactors,
    ) {
        Assert::allString($this->explanationFactors, 'Chaque facteur explicatif doit être une chaîne de caractères.');
    }

    /**
     * @param array{knowledge: float, experience: float, tolerance: float} $appliedWeights
     * @param list<string>                                                 $explanationFactors
     */
    public static function build(
        string $engineVersion,
        float $knowledgeScore,
        float $experienceScore,
        float $toleranceScore,
        InvestorProfileLevel $capacityLevel,
        InvestorProfileLevel $toleranceLevel,
        InvestorProfileLevel $rawProfile,
        array $appliedWeights,
        float $rawScore,
        array $explanationFactors,
    ): self {
        $toleranceCapValue = min(7, $toleranceLevel->value + 1);
        $finalValue = min($rawProfile->value, $capacityLevel->value, $toleranceCapValue);
        $finalProfile = InvestorProfileLevel::from($finalValue);

        return new self(
            engineVersion: $engineVersion,
            knowledgeScore: $knowledgeScore,
            experienceScore: $experienceScore,
            toleranceScore: $toleranceScore,
            capacityLevel: $capacityLevel,
            toleranceLevel: $toleranceLevel,
            rawProfile: $rawProfile,
            finalProfile: $finalProfile,
            cappedByCapacity: $finalValue === $capacityLevel->value && $capacityLevel->value < $rawProfile->value,
            cappedByTolerance: $finalValue === $toleranceCapValue && $toleranceCapValue < $rawProfile->value,
            appliedWeights: $appliedWeights,
            rawScore: $rawScore,
            explanationFactors: $explanationFactors,
        );
    }
}
