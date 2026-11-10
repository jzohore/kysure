<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Application\AdequacyReport\DTO\Response\AdequacyReportDocument;
use App\Application\AdequacyReport\DTO\Response\AdequacyReportProductSection;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\ValueObject\CostBreakdown;

/**
 * Met en forme le contenu figé d'un rapport d'adéquation pour le PDF et l'aperçu du brouillon.
 * Ne lit QUE `content` (jamais l'entité vivante d'un produit, d'une fiche ou du cabinet) :
 * le PDF d'un rapport archivé est donc reproductible à l'identique.
 *
 * Lecture tolérante : une vérification antérieure au lot 4 n'a pas de frais figés, le
 * document l'indique au lieu de recalculer quoi que ce soit.
 */
final readonly class AdequacyReportDocumentAssembler
{
    public function fromReport(AdequacyReport $report): AdequacyReportDocument
    {
        return $this->assemble($report->content, $report);
    }

    /**
     * @param array<string, mixed> $content contenu d'un brouillon, voir {@see AdequacyReport::composeContent()}
     */
    public function fromDraft(array $content): AdequacyReportDocument
    {
        return $this->assemble($content, null);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function assemble(array $content, ?AdequacyReport $report): AdequacyReportDocument
    {
        $cabinet = $this->map($content['cabinet'] ?? null);
        $client = $this->map($content['client'] ?? null);
        $profile = $this->map($content['investorProfile'] ?? null);
        $netWorth = $this->map($client['netWorth'] ?? null);

        $objectives = [];
        foreach ($this->list($client['objectives'] ?? null) as $objective) {
            $amount = $objective['amountInCents'] ?? null;
            $objectives[] = [
                'label' => $this->str($objective['label'] ?? null),
                'priority' => $this->str($objective['priorityLabel'] ?? null),
                'horizon' => $this->str($objective['horizonLabel'] ?? null),
                'amount' => \is_int($amount) ? self::euros($amount) : '—',
            ];
        }

        $netWorthLines = [];
        foreach ($this->list($netWorth['lines'] ?? null) as $line) {
            $netWorthLines[] = ['label' => $this->str($line['label'] ?? null), 'amount' => self::euros($this->int($line['amountInCents'] ?? null))];
        }

        $products = array_map($this->productSection(...), $this->list($content['checks'] ?? null));

        return new AdequacyReportDocument(
            isDraft: !$report instanceof AdequacyReport,
            version: $report?->version,
            validatedAt: $report?->validatedAt,
            validatedByName: $report?->validatedByName,
            contentHash: $report?->contentHash,
            revokedAt: $report?->revokedAt,
            revokeReason: $report?->revokeReason,
            folderReference: $this->str($content['folderReference'] ?? null),
            cabinetName: $this->str($cabinet['name'] ?? null),
            cabinetLegalName: $this->nullableStr($cabinet['legalName'] ?? null),
            cabinetAddress: $this->nullableStr($cabinet['address'] ?? null),
            cabinetSiret: $this->nullableStr($cabinet['siret'] ?? null),
            cabinetOriasNumber: $this->nullableStr($cabinet['oriasNumber'] ?? null),
            cabinetLogoStoragePath: $this->nullableStr($cabinet['logoStoragePath'] ?? null),
            clientFullName: $this->str($client['fullName'] ?? null),
            clientSituation: $this->situationLines($this->map($client['situation'] ?? null)),
            objectives: $objectives,
            netWorthLines: $netWorthLines,
            netWorthTotals: [] === $netWorth ? null : [
                'gross' => self::euros($this->int($netWorth['grossWorthInCents'] ?? null)),
                'debt' => self::euros($this->int($netWorth['outstandingDebtInCents'] ?? null)),
                'net' => self::euros($this->int($netWorth['netWorthInCents'] ?? null)),
                'income' => self::euros($this->int($netWorth['annualIncomeInCents'] ?? null)),
                'expenses' => self::euros($this->int($netWorth['annualExpensesInCents'] ?? null)),
                'capacity' => self::euros($this->int($netWorth['investmentCapacityInCents'] ?? null)),
            ],
            profileLevel: $this->int($profile['retainedLevel'] ?? null),
            profileLabel: $this->str($profile['retainedLabel'] ?? null),
            profileVersion: $this->int($profile['version'] ?? null),
            profileValidatedAt: new \DateTimeImmutable($this->str($profile['validatedAt'] ?? null, 'now')),
            profileOverridden: true === ($profile['overridden'] ?? false),
            products: $products,
            advisorRationale: $this->str($content['advisorRationale'] ?? null),
            periodicReviewRequired: \is_bool($content['periodicReviewRequired'] ?? null) ? $content['periodicReviewRequired'] : null,
            madeAvailableAt: $report?->madeAvailableAt,
            pdfSha256: $report?->pdfSha256,
        );
    }

    /**
     * @param array<string, mixed> $check
     */
    private function productSection(array $check): AdequacyReportProductSection
    {
        $summary = $this->map($check['summary'] ?? null);
        $checkContent = $this->map($check['content'] ?? null);

        $characteristics = [];
        $isin = $this->nullableStr($summary['isin'] ?? null);
        if (null !== $isin) {
            $characteristics[] = ['label' => 'Code ISIN', 'value' => $isin];
        }

        $characteristics[] = ['label' => 'Famille', 'value' => $this->str($summary['familyLabel'] ?? null, '—')];
        if (\is_int($summary['sriLevel'] ?? null)) {
            $characteristics[] = ['label' => 'Niveau de risque (SRI)', 'value' => $summary['sriLevel'] . '/7'];
        }

        if (\is_int($summary['minimumHorizonYears'] ?? null)) {
            $years = $summary['minimumHorizonYears'];
            $characteristics[] = ['label' => 'Horizon de placement recommandé', 'value' => sprintf('%d an%s minimum', $years, $years > 1 ? 's' : '')];
        }

        if (\is_int($summary['annualFeesBasisPoints'] ?? null)) {
            $characteristics[] = ['label' => 'Frais annuels du produit', 'value' => self::percent($summary['annualFeesBasisPoints']) . ' / an'];
        }

        $characteristics[] = ['label' => 'Classification SFDR', 'value' => $this->str($summary['sfdrLabel'] ?? null, 'Non renseignée')];

        $objective = $this->str($summary['objectiveLabel'] ?? null, '—');
        $horizon = $this->nullableStr($summary['objectiveHorizonLabel'] ?? null);
        if (null !== $horizon) {
            $objective .= ' — ' . mb_strtolower($horizon);
        }

        $findings = [];
        foreach ($this->list($summary['findings'] ?? null) as $finding) {
            $findings[] = [
                'criterion' => $this->str($finding['criterion'] ?? null),
                'severity' => $this->str($finding['severity'] ?? null),
                'severityLabel' => $this->str($finding['severityLabel'] ?? null),
                'message' => $this->str($finding['message'] ?? null),
            ];
        }

        $costs = \is_array($checkContent['costs'] ?? null) ? $this->costs($checkContent['costs']) : null;

        return new AdequacyReportProductSection(
            productName: $this->str($summary['productName'] ?? null),
            amount: self::euros($this->int($check['amountInCents'] ?? null)),
            objective: $objective,
            characteristics: $characteristics,
            worstSeverity: $this->str($check['worstSeverity'] ?? null),
            worstSeverityLabel: $this->str($check['worstSeverityLabel'] ?? null),
            findings: $findings,
            justification: $this->nullableStr($check['justification'] ?? null),
            costLines: $costs['lines'] ?? null,
            costTotal: $costs['total'] ?? null,
            cifRemunerationOverHorizon: $costs['cif'] ?? null,
            costsFootnote: $costs['footnote'] ?? null,
            checkedByName: $this->str($check['checkedByName'] ?? null),
            checkedAt: new \DateTimeImmutable($this->str($check['checkedAt'] ?? null, 'now')),
            checkContentHash: $this->str($check['contentHash'] ?? null),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{lines: list<array{label: string, rate: string, amount: string}>, total: array{label: string, rate: string, amount: string}, cif: string, footnote: string}
     */
    private function costs(array $data): array
    {
        /** @var array<string, mixed> $data */
        $costs = CostBreakdown::fromArray($data);
        $remuneration = $costs->cifRemuneration;
        $years = sprintf('%d an%s', $costs->horizonYears, $costs->horizonYears > 1 ? 's' : '');

        return [
            'lines' => [
                ['label' => 'Frais annuels du produit', 'rate' => self::percent($costs->annualFeesBasisPoints) . ' / an', 'amount' => self::euros($costs->annualFeesInCents) . ' / an'],
                ['label' => 'Effet cumulé des frais annuels sur ' . $years, 'rate' => self::percent($costs->annualFeesBasisPoints * $costs->horizonYears), 'amount' => self::euros($costs->cumulativeAnnualFeesInCents)],
                ['label' => 'Droits d\'entrée (rémunération du CIF)', 'rate' => self::percent($remuneration->entryFeeBasisPoints), 'amount' => self::euros($costs->entryFeeInCents)],
                ['label' => 'Honoraires de conseil (rémunération du CIF)', 'rate' => '—', 'amount' => self::euros($remuneration->advisoryFeeInCents)],
                ['label' => 'Rétrocessions (rémunération du CIF, comprises dans les frais du produit)', 'rate' => self::percent($remuneration->annualRetrocessionBasisPoints) . ' / an', 'amount' => self::euros($costs->annualRetrocessionInCents) . ' / an'],
            ],
            'total' => ['label' => 'Coût total sur ' . $years, 'rate' => self::percent($costs->totalCostsBasisPoints), 'amount' => self::euros($costs->totalCostsInCents)],
            'cif' => self::euros($costs->cifRemunerationOverHorizonInCents()),
            'footnote' => sprintf(
                'Sur %s (horizon minimum du produit), sur le montant investi net des droits d\'entrée (%s), à capital constant : aucune hypothèse de performance. Taux total rapporté au montant investi (%s).',
                $years,
                self::euros($costs->netInvestedInCents()),
                self::euros($costs->amountInCents),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $situation
     *
     * @return list<array{label: string, value: string}>
     */
    private function situationLines(array $situation): array
    {
        $lines = [];
        $civility = $this->nullableStr($situation['civility'] ?? null);
        $birthDate = $this->nullableStr($situation['birthDate'] ?? null);
        if (null !== $birthDate) {
            $lines[] = ['label' => 'Date de naissance', 'value' => new \DateTimeImmutable($birthDate)->format('d/m/Y')];
        }

        if (null !== $civility) {
            array_unshift($lines, ['label' => 'Civilité', 'value' => $civility]);
        }

        $marital = $this->nullableStr($situation['maritalStatus'] ?? null);
        if (null !== $marital) {
            $children = $situation['childrenCount'] ?? null;
            $lines[] = ['label' => 'Situation familiale', 'value' => $marital . (\is_int($children) ? sprintf(', %d enfant%s', $children, $children > 1 ? 's' : '') : '')];
        }

        $professional = $this->nullableStr($situation['professionalStatus'] ?? null);
        if (null !== $professional) {
            $profession = $this->nullableStr($situation['profession'] ?? null);
            $lines[] = ['label' => 'Situation professionnelle', 'value' => $professional . (null !== $profession ? ' — ' . $profession : '')];
        }

        return $lines;
    }

    /** Centimes → « 1 234,56 € », sans flottant. */
    public static function euros(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%s,%02d €', $sign, number_format(intdiv($cents, 100), 0, ',', ' '), $cents % 100);
    }

    /** Points de base → « 1,25 % », sans flottant. */
    public static function percent(int $basisPoints): string
    {
        return sprintf('%d,%02d %%', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        /** @var array<string, mixed> $map */
        $map = \is_array($value) ? $value : [];

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter($value, \is_array(...)));

        return $items;
    }

    private function str(mixed $value, string $default = ''): string
    {
        return \is_string($value) && '' !== $value ? $value : $default;
    }

    private function nullableStr(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function int(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}
