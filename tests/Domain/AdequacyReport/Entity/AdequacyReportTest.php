<?php

declare(strict_types=1);

namespace App\Tests\Domain\AdequacyReport\Entity;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;

final class AdequacyReportTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testValidationFreezesACopyOfEverythingAndSealsIt(): void
    {
        $check = $this->check();

        $report = $this->validateReport([$check]);

        self::assertStringStartsWith('adr_', $report->slugId);
        self::assertSame(1, $report->version);
        self::assertSame($this->client, $report->client);
        self::assertSame($this->profile, $report->investorProfile);
        self::assertSame($this->cgp->getFullName(), $report->validatedByName);
        self::assertTrue($report->isInForce());
        self::assertFalse($report->isPdfAvailable());
        self::assertSame(1, $report->checkCount());
        self::assertTrue($report->matchesStoredHash());
        self::assertSame(64, \strlen($report->contentHash));

        // Copie, jamais jointure : la vérification citée est recopiée à l'identique, frais compris.
        $cited = $report->content['checks'][0];
        self::assertSame($check->content, $cited['content']);
        self::assertSame($check->contentHash, $cited['contentHash']);
        self::assertArrayHasKey('costs', $cited['content']);
        self::assertSame('ETF MSCI World', $cited['summary']['productName']);
        self::assertSame($this->client->getFullName(), $report->content['client']['fullName']);
        self::assertSame(4, $report->content['investorProfile']['retainedLevel']);
        self::assertSame('KYC-2026-001', $report->content['folderReference']);
    }

    public function testRationaleIsMandatoryAndMustBeWritten(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/justification du conseil/');

        $this->validateReport(rationale: '  Produit adapté.  ');
    }

    public function testAtLeastOneCheckMustBeCited(): void
    {
        $this->expectException(\DomainException::class);

        $this->validateReport(checks: []);
    }

    public function testACheckCannotBeCitedTwice(): void
    {
        $check = $this->check();

        $this->expectException(\DomainException::class);

        $this->validateReport([$check, $check]);
    }

    public function testCheckFromAnotherFolderIsRejected(): void
    {
        $otherFolder = $this->createEntityState(IndividualFolder::class, ['slugId' => 'fld_2', 'reference' => 'KYC-2026-002', 'workspace' => $this->workspace, 'client' => $this->client]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/n\'appartient pas à ce dossier/');

        $this->validateReport([$this->check(folder: $otherFolder)]);
    }

    public function testCheckDoneOnAPreviousProfileVersionIsRejected(): void
    {
        $previousProfile = $this->profileState(version: 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/version antérieure du profil/');

        $this->validateReport([$this->check(profile: $previousProfile)]);
    }

    public function testRevokedProfileCannotFoundAReport(): void
    {
        $check = $this->check();
        new \ReflectionProperty($this->profile::class, 'revokedAt')->setValue($this->profile, new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/révoqué/');

        $this->validateReport([$check]);
    }

    public function testTamperedCheckCannotBeCited(): void
    {
        $check = $this->check();
        $content = $check->content;
        $content['findings'][0]['message'] = 'Constat réécrit après coup.';
        new \ReflectionProperty(ProductAdequacyCheck::class, 'content')->setValue($check, $content);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/empreinte invalide/');

        $this->validateReport([$check]);
    }

    public function testArchivedFolderCannotReceiveAReport(): void
    {
        $check = $this->check();
        new \ReflectionProperty(ComplianceFolder::class, 'status')->setValue($this->folder, ComplianceFolderStatus::ARCHIVED);

        $this->expectException(\DomainException::class);

        $this->validateReport([$check]);
    }

    public function testCheckRecordedBeforeCostsWereFrozenCannotFoundAReport(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/avant le calcul des frais/');

        $this->validateReport([$this->legacyCheck()]);
    }

    public function testPeriodicReviewMentionMustBeDecided(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/réexamen périodique/');

        $this->validateReport(periodicReviewRequired: null);
    }

    public function testPeriodicReviewMentionIsFrozen(): void
    {
        self::assertFalse($this->validateReport(periodicReviewRequired: false)->content['periodicReviewRequired']);
    }

    public function testCabinetMustBeIdentifiedByItsOriasNumber(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/ORIAS/');

        $this->validateReport(cabinetOverrides: ['oriasNumber' => null]);
    }

    public function testMadeAvailableOnceWithTheFileFingerprint(): void
    {
        $report = $this->validateReport();
        $report->markMadeAvailable('documents/rapport.pdf', hash('sha256', 'pdf'));

        self::assertTrue($report->isPdfAvailable());
        self::assertSame(hash('sha256', 'pdf'), $report->pdfSha256);
        self::assertNotNull($report->madeAvailableAt);

        $this->expectException(\DomainException::class);
        $report->markMadeAvailable('documents/autre.pdf', hash('sha256', 'autre'));
    }

    public function testTamperedReportNoLongerMatchesItsHash(): void
    {
        $report = $this->validateReport();
        $content = $report->content;
        $content['advisorRationale'] = 'Justification réécrite après la remise au client.';
        new \ReflectionProperty(AdequacyReport::class, 'content')->setValue($report, $content);

        self::assertFalse($report->matchesStoredHash());
    }

    public function testDraftContentIsExactlyWhatGetsFrozen(): void
    {
        $check = $this->check();
        $report = $this->validateReport([$check]);

        $draft = AdequacyReport::composeContent($this->folder, $this->profile, [$check], $report->content['advisorRationale'], true, $report->content['cabinet'], array_diff_key($report->content['client'], ['fullName' => true]));

        self::assertSame($report->content, $draft);
    }

    public function testRevocationKeepsTheReportAndRecordsWhoAndWhy(): void
    {
        $report = $this->validateReport();
        $contentBefore = $report->content;

        $report->revoke($this->cgp, '  Nouveau profil investisseur validé.  ');

        self::assertTrue($report->isRevoked());
        self::assertFalse($report->isInForce());
        self::assertSame('Nouveau profil investisseur validé.', $report->revokeReason);
        self::assertSame($this->cgp->getFullName(), $report->revokedByName);
        self::assertSame($contentBefore, $report->content, 'La révocation ne touche jamais au contenu figé.');
        self::assertTrue($report->matchesStoredHash());
    }

    public function testRevocationRequiresAReason(): void
    {
        $report = $this->validateReport();

        $this->expectException(\DomainException::class);

        $report->revoke($this->cgp, '   ');
    }

    public function testAReportCannotBeRevokedTwice(): void
    {
        $report = $this->validateReport();
        $report->revoke($this->cgp, 'Erreur de produit.');

        $this->expectException(\DomainException::class);

        $report->revoke($this->cgp, 'Encore.');
    }

    public function testReportIsFlaggedWhenItsProfileIsRevokedAfterwards(): void
    {
        $report = $this->validateReport();
        self::assertFalse($report->isBasedOnRevokedProfile());

        new \ReflectionProperty($this->profile::class, 'revokedAt')->setValue($this->profile, new \DateTimeImmutable());

        self::assertTrue($report->isBasedOnRevokedProfile(), 'Le rapport est signalé…');
        self::assertTrue($report->isInForce(), '… mais ni révoqué ni modifié automatiquement.');
        self::assertTrue($report->matchesStoredHash());
    }
}
