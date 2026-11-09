<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO\Response;

/**
 * Données de présentation du rapport d'adéquation (PDF et aperçu du brouillon), entièrement
 * dérivées du contenu figé : aucune donnée vivante n'y est relue. Montants et taux déjà mis en
 * forme (centimes et points de base, jamais de flottant).
 */
final readonly class AdequacyReportDocument
{
    /**
     * @param list<array{label: string, value: string}>                                                                $clientSituation
     * @param list<array{label: string, priority: string, horizon: string, amount: string}>                            $objectives
     * @param list<array{label: string, amount: string}>                                                               $netWorthLines
     * @param array{gross: string, debt: string, net: string, income: string, expenses: string, capacity: string}|null $netWorthTotals
     * @param list<AdequacyReportProductSection>                                                                       $products
     */
    public function __construct(
        public bool $isDraft,
        public ?int $version,
        public ?\DateTimeImmutable $validatedAt,
        public ?string $validatedByName,
        public ?string $contentHash,
        public ?\DateTimeImmutable $revokedAt,
        public ?string $revokeReason,
        public string $folderReference,
        public string $cabinetName,
        public ?string $cabinetLegalName,
        public ?string $cabinetAddress,
        public ?string $cabinetSiret,
        public ?string $cabinetOriasNumber,
        public ?string $cabinetLogoStoragePath,
        public string $clientFullName,
        public array $clientSituation,
        public array $objectives,
        public array $netWorthLines,
        public ?array $netWorthTotals,
        public int $profileLevel,
        public string $profileLabel,
        public int $profileVersion,
        public \DateTimeImmutable $profileValidatedAt,
        public bool $profileOverridden,
        public array $products,
        public string $advisorRationale,
    ) {
    }
}
