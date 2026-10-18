<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr\Parser;

use App\Infrastructure\Ocr\Parser\FrenchDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrenchDateParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function datesProvider(): iterable
    {
        yield 'numérique avec slashs' => ['03/01/2026', '2026-01-03'];
        yield 'numérique avec points' => ['3.1.2026', '2026-01-03'];
        yield 'numérique avec tirets' => ['03-01-2026', '2026-01-03'];
        yield 'mois en toutes lettres' => ['3 janvier 2026', '2026-01-03'];
        yield 'mois accentué' => ['12 février 2026', '2026-02-12'];
        yield 'août' => ['15 août 2026', '2026-08-15'];
        yield 'mois abrégé avec point' => ['3 janv. 2026', '2026-01-03'];
        yield 'premier du mois' => ['1er mars 2026', '2026-03-01'];
        yield 'noyé dans du texte' => ['Facture du 03/01/2026 - Page 1', '2026-01-03'];
        yield 'date impossible' => ['31/02/2026', null];
        yield 'mois inconnu' => ['3 foobar 2026', null];
        yield 'aucune date' => ['Madame Dupont', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('datesProvider')]
    public function testParsesFrenchDatesToIso(string $raw, ?string $expected): void
    {
        self::assertSame($expected, FrenchDateParser::parse($raw));
    }
}
