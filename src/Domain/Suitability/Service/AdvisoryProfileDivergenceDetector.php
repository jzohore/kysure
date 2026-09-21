<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Service;

use App\Domain\Compliance\Enum\AdvisoryRiskProfile;

/**
 * Détecte une divergence significative entre le profil perçu à l'entretien
 * ({@see AdvisoryRiskProfile}, 5 niveaux) et le profil validé du questionnaire
 * ({@see \App\Domain\Suitability\Enum\InvestorProfileLevel}, 1-7, qui fait foi) — décision
 * lot 5 : simple alerte visuelle pour le CGP, jamais de blocage. Un écart d'un seul cran est
 * une variance normale entre impression d'entretien et réponses formelles ; à partir de deux
 * crans, la divergence mérite d'être signalée.
 */
final class AdvisoryProfileDivergenceDetector
{
    private const int SIGNIFICANT_GAP_THRESHOLD = 2;

    public function detect(AdvisoryRiskProfile $advisoryProfile, int $investorProfileLevel): bool
    {
        $expectedLevel = $advisoryProfile->equivalentInvestorProfileLevel();

        if (null === $expectedLevel) {
            return false;
        }

        return abs($investorProfileLevel - $expectedLevel) >= self::SIGNIFICANT_GAP_THRESHOLD;
    }
}
