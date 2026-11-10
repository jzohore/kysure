<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\ValueObject;

use App\Domain\Compliance\ValueObject\DerStatement;
use PHPUnit\Framework\TestCase;

final class DerStatementTest extends TestCase
{
    public function testCurrentStatementIncludesTheElectronicDeliveryConsent(): void
    {
        $statement = DerStatement::current();

        self::assertTrue(DerStatement::coversElectronicDelivery($statement->version));
        self::assertStringContainsString('support durable électronique', $statement->text);
    }

    public function testAcknowledgementOfAnOlderStatementIsNotAConsent(): void
    {
        self::assertFalse(DerStatement::coversElectronicDelivery('2026-09-01'));
        self::assertTrue(DerStatement::coversElectronicDelivery('2027-01-15'));
    }
}
