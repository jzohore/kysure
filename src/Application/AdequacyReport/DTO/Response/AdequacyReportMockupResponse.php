<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO\Response;

/**
 * Données de présentation de la maquette du rapport d'adéquation (lot 0 du chantier — voir la
 * feuille de route CIF Pilot phase 2). Aucun champ ici ne provient d'une entité persistée :
 * seule l'identité du cabinet est réelle (déjà publique dans l'app), tout le reste (client,
 * patrimoine, produit, frais, projections) est fictif et volontairement présenté comme tel.
 */
readonly class AdequacyReportMockupResponse
{
    /**
     * @param list<array{label: string, priority: string, amount: string, horizon: string}>                                $objectives
     * @param list<array{label: string, amount: string}>                                                                   $netWorthLines
     * @param list<array{label: string, amount: string, note: string}>                                                     $feeLines
     * @param list<array{name: string, hypothesis: string, finalValue: string, cumulativeFees: string, netReturn: string}> $scenarios
     */
    public function __construct(
        public string $workspaceName,
        public ?string $workspaceLegalName,
        public ?string $workspaceAddress,
        public ?string $workspaceSiret,
        public ?string $workspaceLogoStoragePath,
        public \DateTimeImmutable $generatedAt,
        public string $clientFullName,
        public string $clientSituationSummary,
        public array $objectives,
        public int $investorProfileLevel,
        public string $investorProfileLabel,
        public array $netWorthLines,
        public string $netWorthGross,
        public string $netWorthNet,
        public string $investmentCapacity,
        public string $productName,
        public string $productProducer,
        public string $productIsin,
        public string $productTypology,
        public int $productSri,
        public string $productRecommendedHorizon,
        public string $productMinInvestment,
        public string $productReturnObjective,
        public string $adequacyJustification,
        public string $cifRemuneration,
        public array $feeLines,
        public string $totalFeesImpact,
        public array $scenarios,
    ) {
    }
}
