<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Application\AdequacyReport\DTO\AdequacyCheckPreview;
use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;
use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use App\Domain\Compliance\Entity\ComplianceFolder;

/**
 * Aperçu des constats et des frais pendant que le CGP choisit produit, objectif et montant. Lecture seule :
 * rien n'est enregistré ni tracé tant qu'il n'a pas validé la vérification.
 */
final readonly class EvaluateProductAdequacyUseCase
{
    public function __construct(
        private PrepareProductAdequacyCheckUseCase $prepare,
        private SuitabilityMatchEvaluator $evaluator,
        private CostBreakdownCalculator $costCalculator,
    ) {
    }

    public function __invoke(ComplianceFolder $folder, string $productSlugId, int $objectiveIndex, int $amountInCents, CifRemuneration $remuneration): AdequacyCheckPreview
    {
        $prepared = ($this->prepare)($folder, $productSlugId, $objectiveIndex, $amountInCents);

        return new AdequacyCheckPreview(
            evaluation: $this->evaluator->evaluate($prepared->input),
            costs: $this->costCalculator->calculate($prepared->input->product, $prepared->input->amountInCents, $remuneration),
        );
    }
}
