<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Enum;

/**
 * Qui a produit une réponse au questionnaire. Porté par chaque réponse individuellement
 * (pas par l'assessment entier) : c'est ce qui permettra, au lot 5, à l'IA de pré-remplir
 * certaines réponses (AI_SUGGESTION) sans que ça se confonde avec une réponse actée par le
 * client — une suggestion IA n'a de valeur probante qu'une fois explicitement confirmée.
 */
enum AnswerSource: string
{
    case CLIENT = 'client';
    case CGP = 'cgp';
    case AI_SUGGESTION = 'ai_suggestion';

    /**
     * Réponse copiée depuis le dernier questionnaire soumis par ce même client auprès d'un
     * autre cabinet (préremplissage de convenance, jamais un transfert de validation — voir
     * {@see \App\Domain\Suitability\Entity\InvestorProfileAssessment::prefillFrom()}). Comme
     * AI_SUGGESTION, n'a pas encore de valeur probante pour CE cabinet : la source repasse à
     * CLIENT dès que la réponse est resauvegardée via le parcours normal (le client avance
     * dans le questionnaire, donc l'a vue et implicitement confirmée ou ajustée).
     */
    case CROSS_WORKSPACE_PREFILL = 'cross_workspace_prefill';

    public function getLabel(): string
    {
        return match ($this) {
            self::CLIENT => 'Client',
            self::CGP => 'Conseiller',
            self::AI_SUGGESTION => 'Suggestion IA (non confirmée)',
            self::CROSS_WORKSPACE_PREFILL => 'Préremplie depuis un autre cabinet (non confirmée)',
        };
    }
}
