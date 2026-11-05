<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Enum\SfdrClassification;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;

/**
 * Instantané des caractéristiques d'un produit du catalogue au moment d'une vérification.
 * Copie, jamais jointure : si le cabinet modifie le produit plus tard, une vérification
 * passée (et le rapport du lot 5 qui la citera) ne change pas de contenu.
 */
final readonly class ProductCharacteristics
{
    /**
     * @param list<InvestorProfileLevel> $targetInvestorProfiles
     */
    public function __construct(
        public string $name,
        public ?string $isin,
        public ProductFamily $family,
        public int $sriLevel,
        public int $minimumHorizonYears,
        public int $annualFeesBasisPoints,
        public array $targetInvestorProfiles,
        public ?SfdrClassification $sfdrClassification,
    ) {
    }

    public static function fromProduct(FinancialProduct $product): self
    {
        return new self(
            name: $product->name,
            isin: $product->isin,
            family: $product->family,
            sriLevel: $product->sriLevel,
            minimumHorizonYears: $product->minimumHorizonYears,
            annualFeesBasisPoints: $product->annualFeesBasisPoints,
            targetInvestorProfiles: $product->targetInvestorProfileLevels(),
            sfdrClassification: $product->sfdrClassification,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isin' => $this->isin,
            'family' => $this->family->value,
            'sriLevel' => $this->sriLevel,
            'minimumHorizonYears' => $this->minimumHorizonYears,
            'annualFeesBasisPoints' => $this->annualFeesBasisPoints,
            'targetInvestorProfiles' => array_map(static fn (InvestorProfileLevel $level): int => $level->value, $this->targetInvestorProfiles),
            'sfdrClassification' => $this->sfdrClassification?->value,
        ];
    }
}
