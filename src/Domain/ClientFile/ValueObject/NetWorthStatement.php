<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\AssetClass;
use Webmozart\Assert\Assert;

/**
 * Situation patrimoniale de la fiche client (EER lot 1). Le patrimoine brut/net, le patrimoine
 * immobilier/financier sont calculés automatiquement à partir des poches saisies — conformément
 * au cahier des charges. La capacité d'investissement disponible, elle, N'EST PAS déduite d'une
 * formule mécanique : c'est un montant que le conseiller saisit lui-même, car il s'agit d'un
 * jugement professionnel (réserve de précaution, dépenses à venir...) et non d'une simple somme
 * de liquidités — l'inventer ici reviendrait à fabriquer une règle réglementaire non demandée.
 */
final readonly class NetWorthStatement
{
    /**
     * @param list<NetWorthLine> $lines
     */
    public function __construct(
        public array $lines,
        public int $annualIncomeInCents,
        public int $annualExpensesInCents,
        public int $outstandingDebtInCents,
        public int $investmentCapacityInCents,
    ) {
        Assert::allIsInstanceOf($lines, NetWorthLine::class);
        Assert::greaterThanEq($annualIncomeInCents, 0, 'Les revenus annuels ne peuvent pas être négatifs.');
        Assert::greaterThanEq($annualExpensesInCents, 0, 'Les charges annuelles ne peuvent pas être négatives.');
        Assert::greaterThanEq($outstandingDebtInCents, 0, 'L\'encours de crédits ne peut pas être négatif.');
        Assert::greaterThanEq($investmentCapacityInCents, 0, 'La capacité d\'investissement ne peut pas être négative.');
    }

    public function grossWorth(): int
    {
        return array_sum(array_map(static fn (NetWorthLine $line): int => $line->amountInCents, $this->lines));
    }

    public function realEstateWorth(): int
    {
        return $this->worthForClass(AssetClass::REAL_ESTATE);
    }

    public function financialWorth(): int
    {
        return $this->grossWorth() - $this->realEstateWorth();
    }

    public function netWorth(): int
    {
        return $this->grossWorth() - $this->outstandingDebtInCents;
    }

    private function worthForClass(AssetClass $assetClass): int
    {
        $matchingLines = array_filter(
            $this->lines,
            static fn (NetWorthLine $line): bool => $line->assetClass === $assetClass,
        );

        return array_sum(array_map(static fn (NetWorthLine $line): int => $line->amountInCents, $matchingLines));
    }
}
