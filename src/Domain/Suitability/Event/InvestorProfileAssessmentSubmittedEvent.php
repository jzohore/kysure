<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Event;

/**
 * Un client a soumis son questionnaire profil investisseur. Le profil calculé n'est encore
 * qu'une proposition — le figeage/validation CGP est un événement séparé, ajouté au lot 2.
 */
final readonly class InvestorProfileAssessmentSubmittedEvent
{
    public function __construct(
        public string $assessmentSlugId,
        public int $finalProfileLevel,
    ) {
    }
}
