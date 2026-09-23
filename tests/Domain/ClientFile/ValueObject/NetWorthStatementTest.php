<?php

declare(strict_types=1);

namespace App\Tests\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\AssetClass;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use PHPUnit\Framework\TestCase;

final class NetWorthStatementTest extends TestCase
{
    public function testComputesGrossNetRealEstateAndFinancialWorth(): void
    {
        $statement = new NetWorthStatement(
            lines: [
                new NetWorthLine(AssetClass::REAL_ESTATE, 180_000_00),
                new NetWorthLine(AssetClass::LIFE_INSURANCE, 65_000_00),
                new NetWorthLine(AssetClass::PEA, 22_000_00),
                new NetWorthLine(AssetClass::SECURITIES_ACCOUNT, 8_000_00),
                new NetWorthLine(AssetClass::REGULATED_SAVINGS, 15_000_00),
                new NetWorthLine(AssetClass::AVAILABLE_LIQUIDITY, 12_000_00),
            ],
            annualIncomeInCents: 96_000_00,
            annualExpensesInCents: 38_000_00,
            outstandingDebtInCents: 24_000_00,
            investmentCapacityInCents: 35_000_00,
        );

        self::assertSame(302_000_00, $statement->grossWorth());
        self::assertSame(180_000_00, $statement->realEstateWorth());
        self::assertSame(122_000_00, $statement->financialWorth());
        self::assertSame(278_000_00, $statement->netWorth());
    }

    public function testRealEstateWorthIsZeroWhenNoRealEstateLine(): void
    {
        $statement = new NetWorthStatement(
            lines: [new NetWorthLine(AssetClass::AVAILABLE_LIQUIDITY, 1_000_00)],
            annualIncomeInCents: 0,
            annualExpensesInCents: 0,
            outstandingDebtInCents: 0,
            investmentCapacityInCents: 0,
        );

        self::assertSame(0, $statement->realEstateWorth());
        self::assertSame(1_000_00, $statement->financialWorth());
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NetWorthLine(AssetClass::PEA, -1);
    }
}
