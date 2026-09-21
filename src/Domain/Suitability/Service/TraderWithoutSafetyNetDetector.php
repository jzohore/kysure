<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Service;

/**
 * Détecte le cas dit « trader sans filet » (§ décision lot 5) : une appétence au risque
 * déclarée très forte alors que la capacité à subir des pertes est très faible. Le double
 * plafonnement de {@see \App\Domain\Suitability\ValueObject\SuitabilityScoreResult::build()}
 * empêche déjà mécaniquement ce cas de produire un profil final élevé — ce détecteur sert
 * uniquement à déclencher une mise en garde écrite tracée en plus du plafonnement, pas à
 * corriger le score lui-même.
 */
final class TraderWithoutSafetyNetDetector
{
    private const int HIGH_TOLERANCE_THRESHOLD = 6;
    private const int LOW_CAPACITY_THRESHOLD = 2;

    /**
     * @param array<string, mixed> $scoreSnapshot voir {@see \App\Domain\Suitability\ValueObject\SuitabilityScoreResult::toArray()}
     */
    public function detect(array $scoreSnapshot): bool
    {
        $tolerance = $scoreSnapshot['toleranceLevel'] ?? null;
        $capacity = $scoreSnapshot['capacityLevel'] ?? null;

        return \is_int($tolerance) && \is_int($capacity)
            && $tolerance >= self::HIGH_TOLERANCE_THRESHOLD
            && $capacity <= self::LOW_CAPACITY_THRESHOLD;
    }
}
