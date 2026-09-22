<?php

declare(strict_types=1);

namespace App\Tests\Domain\ProductCatalogue\Entity;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Workspace\Entity\Workspace;
use PHPUnit\Framework\TestCase;

final class FinancialProductTest extends TestCase
{
    private function createProduct(): FinancialProduct
    {
        return FinancialProduct::create(
            workspace: $this->createStub(Workspace::class),
            name: 'Fonds Euro Sérénité',
            isin: 'FR0000000001',
            family: ProductFamily::ASSURANCE_VIE,
            sriLevel: 2,
            minimumHorizonYears: 4,
            annualFeesBasisPoints: 150,
            targetInvestorProfiles: [InvestorProfileLevel::PRUDENT->value, InvestorProfileLevel::EQUILIBRE->value],
        );
    }

    public function testCreateAttachesAllTheFields(): void
    {
        $product = $this->createProduct();

        self::assertSame('Fonds Euro Sérénité', $product->name);
        self::assertSame('FR0000000001', $product->isin);
        self::assertSame(ProductFamily::ASSURANCE_VIE, $product->family);
        self::assertSame(2, $product->sriLevel);
        self::assertFalse($product->isArchived());
        self::assertSame([InvestorProfileLevel::PRUDENT, InvestorProfileLevel::EQUILIBRE], $product->targetInvestorProfileLevels());
    }

    public function testCreateRejectsAnInvalidSriLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FinancialProduct::create(
            workspace: $this->createStub(Workspace::class),
            name: 'Produit hors échelle',
            isin: null,
            family: ProductFamily::OPCVM_ETF,
            sriLevel: 8,
            minimumHorizonYears: 0,
            annualFeesBasisPoints: 100,
            targetInvestorProfiles: [InvestorProfileLevel::DYNAMIQUE->value],
        );
    }

    public function testCreateRejectsAnEmptyTargetAudience(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FinancialProduct::create(
            workspace: $this->createStub(Workspace::class),
            name: 'Produit sans cible',
            isin: null,
            family: ProductFamily::OPCVM_ETF,
            sriLevel: 3,
            minimumHorizonYears: 2,
            annualFeesBasisPoints: 100,
            targetInvestorProfiles: [],
        );
    }

    public function testCreateRejectsAMalformedIsin(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FinancialProduct::create(
            workspace: $this->createStub(Workspace::class),
            name: 'ISIN invalide',
            isin: 'pas-un-isin',
            family: ProductFamily::OPCVM_ETF,
            sriLevel: 3,
            minimumHorizonYears: 2,
            annualFeesBasisPoints: 100,
            targetInvestorProfiles: [InvestorProfileLevel::DYNAMIQUE->value],
        );
    }

    public function testArchiveThenReactivate(): void
    {
        $product = $this->createProduct();

        $product->archive();
        self::assertTrue($product->isArchived());

        $product->reactivate();
        self::assertFalse($product->isArchived());
    }

    public function testArchivingTwiceIsRejected(): void
    {
        $product = $this->createProduct();
        $product->archive();

        $this->expectException(\DomainException::class);

        $product->archive();
    }
}
