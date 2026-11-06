<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO;

use App\Domain\AdequacyReport\ValueObject\AdequacyEvaluation;
use App\Domain\AdequacyReport\ValueObject\CostBreakdown;

/**
 * Aperçu d'une vérification d'adéquation avant enregistrement : constats du moteur et
 * décomposition des frais, calculés sur les mêmes entrées.
 */
final readonly class AdequacyCheckPreview
{
    public function __construct(
        public AdequacyEvaluation $evaluation,
        public CostBreakdown $costs,
    ) {
    }
}
