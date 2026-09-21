<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Event;

/**
 * Un client a démarré (ou repris) son questionnaire profil investisseur.
 */
final readonly class InvestorProfileAssessmentStartedEvent
{
    public function __construct(
        public string $assessmentSlugId,
    ) {
    }
}
