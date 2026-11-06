<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use App\Domain\AdequacyReport\Service\CostBreakdownCalculator;

/**
 * Décomposition ex-ante des coûts d'un investissement (MIF2), produite par
 * {@see CostBreakdownCalculator} et figée telle quelle dans une vérification d'adéquation.
 *
 * Chaque ligne est conservée séparément (jamais seulement un agrégat) : un contrôleur doit
 * retrouver le coût du produit et la rémunération du CIF distinctement. `fromArray()` relit
 * les valeurs figées sans rien recalculer : une vérification passée reste ce qu'elle était,
 * même si le calcul évolue ensuite.
 *
 * Montants en centimes, taux en points de base.
 */
final readonly class CostBreakdown
{
    public function __construct(
        public string $calculatorVersion,
        public int $amountInCents,
        public int $horizonYears,
        public CifRemuneration $cifRemuneration,
        public int $entryFeeInCents,
        public int $annualFeesBasisPoints,
        public int $annualFeesInCents,
        public int $cumulativeAnnualFeesInCents,
        public int $annualRetrocessionInCents,
        public int $cumulativeRetrocessionInCents,
        public int $totalCostsInCents,
        public int $totalCostsBasisPoints,
    ) {
    }

    /** Montant réellement investi dans le produit, une fois les droits d'entrée prélevés. */
    public function netInvestedInCents(): int
    {
        return $this->amountInCents - $this->entryFeeInCents;
    }

    /** Ce que perçoit le CIF sur la durée : droits d'entrée + honoraires + rétrocessions. */
    public function cifRemunerationOverHorizonInCents(): int
    {
        return $this->entryFeeInCents + $this->cifRemuneration->advisoryFeeInCents + $this->cumulativeRetrocessionInCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calculatorVersion' => $this->calculatorVersion,
            'amountInCents' => $this->amountInCents,
            'horizonYears' => $this->horizonYears,
            'cifRemuneration' => $this->cifRemuneration->toArray(),
            'entryFeeInCents' => $this->entryFeeInCents,
            'annualFeesBasisPoints' => $this->annualFeesBasisPoints,
            'annualFeesInCents' => $this->annualFeesInCents,
            'cumulativeAnnualFeesInCents' => $this->cumulativeAnnualFeesInCents,
            'annualRetrocessionInCents' => $this->annualRetrocessionInCents,
            'cumulativeRetrocessionInCents' => $this->cumulativeRetrocessionInCents,
            'totalCostsInCents' => $this->totalCostsInCents,
            'totalCostsBasisPoints' => $this->totalCostsBasisPoints,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{entryFeeBasisPoints: int, advisoryFeeInCents: int, annualRetrocessionBasisPoints: int} $remuneration */
        $remuneration = $data['cifRemuneration'];

        return new self(
            calculatorVersion: (string) $data['calculatorVersion'],
            amountInCents: self::int($data, 'amountInCents'),
            horizonYears: self::int($data, 'horizonYears'),
            cifRemuneration: CifRemuneration::fromArray($remuneration),
            entryFeeInCents: self::int($data, 'entryFeeInCents'),
            annualFeesBasisPoints: self::int($data, 'annualFeesBasisPoints'),
            annualFeesInCents: self::int($data, 'annualFeesInCents'),
            cumulativeAnnualFeesInCents: self::int($data, 'cumulativeAnnualFeesInCents'),
            annualRetrocessionInCents: self::int($data, 'annualRetrocessionInCents'),
            cumulativeRetrocessionInCents: self::int($data, 'cumulativeRetrocessionInCents'),
            totalCostsInCents: self::int($data, 'totalCostsInCents'),
            totalCostsBasisPoints: self::int($data, 'totalCostsBasisPoints'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Instantané des frais illisible : « %s » doit être un entier.', $key));
        }

        return $value;
    }
}
