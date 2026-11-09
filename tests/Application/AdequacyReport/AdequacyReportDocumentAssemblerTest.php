<?php

declare(strict_types=1);

namespace App\Tests\Application\AdequacyReport;

use App\Application\AdequacyReport\UseCase\AdequacyReportDocumentAssembler;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;

final class AdequacyReportDocumentAssemblerTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testDocumentIsBuiltFromTheFrozenContentOnly(): void
    {
        $report = $this->validateReport();

        $document = new AdequacyReportDocumentAssembler()->fromReport($report);

        self::assertFalse($document->isDraft);
        self::assertSame(1, $document->version);
        self::assertSame($report->contentHash, $document->contentHash);
        self::assertSame('Horizon Patrimoine SAS', $document->cabinetLegalName);
        self::assertSame('12345678', $document->cabinetOriasNumber);
        self::assertSame(4, $document->profileLevel);
        self::assertSame('35 000,00 €', $document->netWorthTotals['capacity'] ?? null);
        self::assertCount(1, $document->products);

        $product = $document->products[0];
        self::assertSame('ETF MSCI World', $product->productName);
        self::assertSame('20 000,00 €', $product->amount);
        self::assertCount(8, $product->findings);
        self::assertNotNull($product->costLines);
        self::assertSame('2,00 %', $product->costLines[2]['rate'], 'Droits d\'entrée de 200 points de base.');
        self::assertNotNull($product->cifRemunerationOverHorizon);
    }

    public function testCheckWithoutFrozenCostsIsShownWithoutRecomputingAnything(): void
    {
        $report = $this->validateReport([$this->legacyCheck()]);

        $product = new AdequacyReportDocumentAssembler()->fromReport($report)->products[0];

        self::assertNull($product->costLines);
        self::assertNull($product->costTotal);
        self::assertNull($product->cifRemunerationOverHorizon);
    }

    public function testDraftHasNoSealYet(): void
    {
        $report = $this->validateReport();

        $document = new AdequacyReportDocumentAssembler()->fromDraft($report->content);

        self::assertTrue($document->isDraft);
        self::assertNull($document->contentHash);
        self::assertNull($document->version);
    }

    public function testRevokedReportCarriesItsRevocation(): void
    {
        $report = $this->validateReport();
        $report->revoke($this->cgp, 'Nouveau profil validé.');

        $document = new AdequacyReportDocumentAssembler()->fromReport($report);

        self::assertNotNull($document->revokedAt);
        self::assertSame('Nouveau profil validé.', $document->revokeReason);
    }

    public function testAmountsAreFormattedWithoutFloats(): void
    {
        self::assertSame('1 234 567,89 €', AdequacyReportDocumentAssembler::euros(123_456_789));
        self::assertSame('0,05 €', AdequacyReportDocumentAssembler::euros(5));
        self::assertSame('1,25 %', AdequacyReportDocumentAssembler::percent(125));
        self::assertSame(AdequacyReport::FORMAT_VERSION, $this->validateReport()->content['formatVersion']);
    }
}
