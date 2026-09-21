<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Service;

use App\Domain\Suitability\ValueObject\SuitabilityAssessmentInput;
use App\Domain\Suitability\ValueObject\SuitabilityScoreResult;

/**
 * Port du moteur de notation du profil investisseur. Versionné explicitement
 * ({@see self::version()}) : une version livrée n'est jamais modifiée rétroactivement, une
 * évolution des pondérations se traduit par une nouvelle implémentation (ScoringEngineV2...).
 */
interface SuitabilityScoringEngineInterface
{
    public function version(): string;

    public function score(SuitabilityAssessmentInput $input): SuitabilityScoreResult;
}
