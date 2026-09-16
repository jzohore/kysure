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

    public function getLabel(): string
    {
        return match ($this) {
            self::CLIENT => 'Client',
            self::CGP => 'Conseiller',
            self::AI_SUGGESTION => 'Suggestion IA (non confirmée)',
        };
    }
}
