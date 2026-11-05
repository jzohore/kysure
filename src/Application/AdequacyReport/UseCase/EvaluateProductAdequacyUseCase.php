<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Domain\AdequacyReport\Service\SuitabilityMatchEvaluator;
use App\Domain\AdequacyReport\ValueObject\AdequacyEvaluation;
use App\Domain\Compliance\Entity\ComplianceFolder;

/**
 * Aperçu des constats pendant que le CGP choisit produit, objectif et montant. Lecture seule :
 * rien n'est enregistré ni tracé tant qu'il n'a pas validé la vérification.
 */
final readonly class EvaluateProductAdequacyUseCase
{
    public function __construct(
        private PrepareProductAdequacyCheckUseCase $prepare,
        private SuitabilityMatchEvaluator $evaluator,
    ) {
    }

    public function __invoke(ComplianceFolder $folder, string $productSlugId, int $objectiveIndex, int $amountInCents): AdequacyEvaluation
    {
        $prepared = ($this->prepare)($folder, $productSlugId, $objectiveIndex, $amountInCents);

        return $this->evaluator->evaluate($prepared->input);
    }
}
