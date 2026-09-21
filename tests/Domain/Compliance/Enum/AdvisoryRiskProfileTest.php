<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Enum;

use App\Domain\Compliance\Enum\AdvisoryRiskProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisoryRiskProfileTest extends TestCase
{
    public function testValuesAreTheFrenchLabels(): void
    {
        self::assertSame('Non déterminé', AdvisoryRiskProfile::NON_DETERMINE->value);
        self::assertSame('Prudent', AdvisoryRiskProfile::PRUDENT->value);
        self::assertSame('Équilibré', AdvisoryRiskProfile::EQUILIBRE->value);
        self::assertSame('Dynamique', AdvisoryRiskProfile::DYNAMIQUE->value);
        self::assertSame('Offensif', AdvisoryRiskProfile::OFFENSIF->value);
    }

    public function testSelectableReturnsEveryCase(): void
    {
        self::assertSame(AdvisoryRiskProfile::cases(), AdvisoryRiskProfile::selectable());
    }

    public function testEquivalentInvestorProfileLevelMapsToTheSharedLabelScale(): void
    {
        self::assertNull(AdvisoryRiskProfile::NON_DETERMINE->equivalentInvestorProfileLevel());
        self::assertSame(2, AdvisoryRiskProfile::PRUDENT->equivalentInvestorProfileLevel());
        self::assertSame(4, AdvisoryRiskProfile::EQUILIBRE->equivalentInvestorProfileLevel());
        self::assertSame(5, AdvisoryRiskProfile::DYNAMIQUE->equivalentInvestorProfileLevel());
        self::assertSame(7, AdvisoryRiskProfile::OFFENSIF->equivalentInvestorProfileLevel());
    }

    #[DataProvider('recognisedLabels')]
    public function testFromLabelReconcilesCasingAndAccents(string $input, AdvisoryRiskProfile $expected): void
    {
        self::assertSame($expected, AdvisoryRiskProfile::fromLabel($input));
    }

    /**
     * @return iterable<string, array{string, AdvisoryRiskProfile}>
     */
    public static function recognisedLabels(): iterable
    {
        yield 'exact' => ['Équilibré', AdvisoryRiskProfile::EQUILIBRE];
        yield 'sans accent' => ['Equilibré', AdvisoryRiskProfile::EQUILIBRE];
        yield 'sans aucun accent' => ['equilibre', AdvisoryRiskProfile::EQUILIBRE];
        yield 'majuscules' => ['ÉQUILIBRÉ', AdvisoryRiskProfile::EQUILIBRE];
        yield 'espaces' => ['  Dynamique  ', AdvisoryRiskProfile::DYNAMIQUE];
        yield 'minuscule' => ['prudent', AdvisoryRiskProfile::PRUDENT];
        yield 'offensif' => ['Offensif', AdvisoryRiskProfile::OFFENSIF];
    }

    #[DataProvider('unknownLabels')]
    public function testFromLabelFallsBackToNonDetermine(?string $input): void
    {
        self::assertSame(AdvisoryRiskProfile::NON_DETERMINE, AdvisoryRiskProfile::fromLabel($input));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unknownLabels(): iterable
    {
        yield 'null' => [null];
        yield 'vide' => [''];
        yield 'espaces seuls' => ['   '];
        yield 'hors liste' => ['Agressif'];
        yield 'charabia' => ['n/a'];
    }
}
