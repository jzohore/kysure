<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Cycle de vie d'un {@see \App\Domain\Suitability\Entity\InvestorProfileAssessment}. Le
 * figeage/validation par le CGP (lot 2) est un objet séparé (ValidatedInvestorProfile,
 * à venir) : un assessment SUBMITTED n'est qu'une proposition de profil, pas encore opposable.
 */
enum AssessmentStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::SUBMITTED => 'Soumis',
        };
    }
}
