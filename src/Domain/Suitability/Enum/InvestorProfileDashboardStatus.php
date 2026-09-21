<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * État du questionnaire profil investisseur tel que perçu par le client sur son tableau de
 * bord, pour le cabinet avec lequel il est actuellement en relation. Distinct de
 * {@see AssessmentStatus} (DRAFT/SUBMITTED uniquement) : ajoute la distinction « soumis mais
 * pas encore validé par le CGP » (le client ne peut plus rien modifier) vs « validé » (fait foi).
 */
enum InvestorProfileDashboardStatus
{
    case NOT_STARTED;
    case IN_PROGRESS;
    case SUBMITTED;
    case VALIDATED;

    public function getCtaLabel(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Répondre',
            self::IN_PROGRESS => 'Continuer',
            self::SUBMITTED => 'Voir mes réponses',
            self::VALIDATED => 'Voir mon profil',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::NOT_STARTED => 'Quelques questions pour que votre conseiller comprenne votre situation et vos objectifs.',
            self::IN_PROGRESS => 'Vous avez commencé à répondre : il vous reste quelques questions pour terminer.',
            self::SUBMITTED => 'Vos réponses ont été transmises à votre conseiller, en attente de son examen.',
            self::VALIDATED => 'Votre conseiller a validé votre profil investisseur.',
        };
    }
}
