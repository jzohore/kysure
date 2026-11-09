<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\DTO\Response;

/**
 * Un produit recommandé dans le rapport : la vérification d'adéquation citée, mise en forme.
 */
final readonly class AdequacyReportProductSection
{
    /**
     * @param list<array{label: string, value: string}>                                                $characteristics
     * @param list<array{criterion: string, severity: string, severityLabel: string, message: string}> $findings
     * @param list<array{label: string, rate: string, amount: string}>|null                            $costLines
     * @param array{label: string, rate: string, amount: string}|null                                  $costTotal
     */
    public function __construct(
        public string $productName,
        public string $amount,
        public string $objective,
        public array $characteristics,
        public string $worstSeverity,
        public string $worstSeverityLabel,
        public array $findings,
        public ?string $justification,
        public ?array $costLines,
        public ?array $costTotal,
        public ?string $cifRemunerationOverHorizon,
        public ?string $costsFootnote,
        public string $checkedByName,
        public \DateTimeImmutable $checkedAt,
        public string $checkContentHash,
    ) {
    }
}
