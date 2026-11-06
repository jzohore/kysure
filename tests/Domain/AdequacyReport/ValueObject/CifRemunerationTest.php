<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport\ValueObject;

use App\Domain\AdequacyReport\ValueObject\CifRemuneration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CifRemunerationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validPercents(): iterable
    {
        yield 'vide' => ['', 0];
        yield 'entier' => ['2', 200];
        yield 'virgule, une décimale' => ['2,5', 250];
        yield 'point, deux décimales' => ['0.75', 75];
        yield 'espaces' => [' 1,05 ', 105];
        yield 'cent pour cent' => ['100', 10_000];
    }

    #[DataProvider('validPercents')]
    public function testConvertsATypedPercentIntoBasisPointsWithoutFloat(string $percent, int $expected): void
    {
        self::assertSame($expected, CifRemuneration::percentToBasisPoints($percent));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPercents(): iterable
    {
        yield 'trois décimales' => ['1,005'];
        yield 'négatif' => ['-1'];
        yield 'texte' => ['deux'];
        yield 'au-delà de 100 %' => ['100,01'];
    }

    #[DataProvider('invalidPercents')]
    public function testRejectsAnInvalidPercent(string $percent): void
    {
        $this->expectException(\DomainException::class);

        CifRemuneration::percentToBasisPoints($percent);
    }

    public function testNegativeAdvisoryFeeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CifRemuneration(advisoryFeeInCents: -1);
    }
}
