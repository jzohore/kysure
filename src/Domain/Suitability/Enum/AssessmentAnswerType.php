<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Forme attendue de la réponse à une {@see QuestionKey} : pilote à la fois le widget de
 * saisie côté client et le cast appliqué avant enregistrement
 * ({@see \App\Domain\Suitability\Entity\InvestorProfileAssessment::recordAnswer()}).
 */
enum AssessmentAnswerType
{
    case SINGLE_CHOICE_INT;
    case SINGLE_CHOICE_STRING;
    case MULTI_CHOICE_STRING;
    case INTEGER;
    case DECIMAL;
    case BOOLEAN;
    case TEXT;
}
