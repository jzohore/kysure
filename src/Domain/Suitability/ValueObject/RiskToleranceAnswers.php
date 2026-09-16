<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\LossReaction;

/**
 * Réponses aux 3 scénarios de perte du questionnaire de tolérance au risque (§3.4).
 * Distincte de la capacité à subir des pertes ({@see LossCapacityInputs}), qui, elle, se
 * calcule à partir de données financières objectives et non d'une réaction déclarée.
 */
final readonly class RiskToleranceAnswers
{
    private function __construct(
        public LossReaction $reactionToMinus10Percent,
        public LossReaction $reactionToMinus20Percent,
        public LossReaction $reactionToSignificantLoss,
    ) {
    }

    public static function fromReactions(
        LossReaction $reactionToMinus10Percent,
        LossReaction $reactionToMinus20Percent,
        LossReaction $reactionToSignificantLoss,
    ): self {
        return new self($reactionToMinus10Percent, $reactionToMinus20Percent, $reactionToSignificantLoss);
    }

    /**
     * Score brut moyen sur les 3 scénarios, échelle 0 à 3 (celle de {@see LossReaction}).
     */
    public function averageScore(): float
    {
        return (
            $this->reactionToMinus10Percent->value
            + $this->reactionToMinus20Percent->value
            + $this->reactionToSignificantLoss->value
        ) / 3;
    }
}
