<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\DTO\Response\FolderChecklistItem;
use App\Application\Compliance\UseCase\ComplianceFolder\GetFolderChecklistUseCase;
use App\Application\Compliance\UseCase\ElectronicDelivery\ElectronicDeliveryConsentResolver;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ElectronicDeliveryConsentRepositoryInterface;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Compliance\ValueObject\ElectronicDeliveryConsentStatement;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class GetFolderChecklistUseCaseTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testIndividualFolderFollowsTheWholeAdviceJourney(): void
    {
        $checklist = $this->useCase(derVersion: DerStatement::VERSION, report: null)($this->folder);

        self::assertSame([
            'DER accusé par le client',
            'Pièces justificatives validées',
            'Accord de remise électronique',
            'Fiche patrimoniale complète',
            'Profil investisseur validé',
            'Produit vérifié',
            'Rapport d\'adéquation validé et remis',
            'Décision de conformité',
        ], array_map(static fn (FolderChecklistItem $item): string => $item->label, $checklist->items));

        self::assertTrue($checklist->items[0]->done, 'DER accusé.');
        self::assertTrue($checklist->items[2]->done, 'Accord donné avec l\'accusé du DER à jour.');
        self::assertNull($checklist->items[2]->action);
        self::assertTrue($checklist->items[4]->done, 'Profil en vigueur.');
        self::assertTrue($checklist->items[5]->done, 'Une vérification avec frais sur le profil en vigueur.');
        self::assertFalse($checklist->items[6]->done);
        self::assertTrue($checklist->items[6]->requiredForApproval);
        self::assertSame('Pièces justificatives validées', $checklist->nextItem()?->label);
    }

    public function testDocumentsAreDoneOnceEveryExpectedPieceIsValid(): void
    {
        $identity = $this->createEntityState(ComplianceDocument::class, ['type' => DocumentType::ID_CARD, 'isMandatory' => true, 'isAskToClient' => false, 'status' => DocumentStatus::VALID]);
        $address = $this->createEntityState(ComplianceDocument::class, ['type' => DocumentType::PROOF_OF_ADDRESS, 'isMandatory' => true, 'isAskToClient' => false, 'status' => DocumentStatus::PENDING]);
        new \ReflectionProperty(ComplianceFolder::class, 'documents')->setValue($this->folder, new ArrayCollection([$identity, $address]));

        $item = $this->useCase(DerStatement::VERSION, null)($this->folder)->items[1];
        self::assertFalse($item->done);
        self::assertSame('1 pièce validée sur 2.', $item->detail);

        new \ReflectionProperty(ComplianceDocument::class, 'status')->setValue($address, DocumentStatus::VALID);
        self::assertTrue($this->useCase(DerStatement::VERSION, null)($this->folder)->items[1]->done);
    }

    public function testOlderDerIsAcknowledgedButTheConsentMustBeAskedByAmendment(): void
    {
        $checklist = $this->useCase(derVersion: '2026-09-01', report: null)($this->folder);

        self::assertTrue($checklist->items[0]->done, 'Le DER est bien accusé, quelle que soit sa version.');
        $consent = $checklist->items[2];
        self::assertFalse($consent->done);
        self::assertSame('requestElectronicDeliveryConsent', $consent->action);
        self::assertSame('Demander l\'accord au client', $consent->actionLabel);
        self::assertStringContainsString('sans renvoyer le DER', $consent->detail);
    }

    public function testPendingAmendmentRequestOffersAReminder(): void
    {
        $this->folder->requestElectronicDeliveryConsent('Marie CURIE');

        $consent = $this->useCase(derVersion: '2026-09-01', report: null)($this->folder)->items[2];

        self::assertFalse($consent->done);
        self::assertSame('Relancer le client', $consent->actionLabel);
        self::assertStringContainsString('Demandé au client le', $consent->detail);
    }

    public function testConsentGivenByAmendmentIsDone(): void
    {
        $amendment = ElectronicDeliveryConsent::give($this->folder, $this->client, ElectronicDeliveryConsentStatement::current());

        $consent = $this->useCase(derVersion: '2026-09-01', report: null, amendment: $amendment)($this->folder)->items[2];

        self::assertTrue($consent->done);
        self::assertStringContainsString('avenant', $consent->detail);
    }

    public function testReportBasedOnARevokedProfileIsNotDone(): void
    {
        $report = $this->validateReport();
        $report->markMadeAvailable('documents/rapport.pdf', hash('sha256', 'pdf'));

        self::assertTrue($this->useCase(DerStatement::VERSION, $report)($this->folder)->items[6]->done);

        new \ReflectionProperty($this->profile::class, 'revokedAt')->setValue($this->profile, new \DateTimeImmutable());

        $item = $this->useCase(DerStatement::VERSION, $report)($this->folder)->items[6];
        self::assertFalse($item->done);
        self::assertStringContainsString('profil investisseur révoqué', $item->detail);
    }

    public function testBusinessFolderOnlyHasDerDocumentsAndDecision(): void
    {
        $folder = $this->createEntityState(BusinessFolder::class, ['slugId' => 'fld_kyb', 'reference' => 'KYB-1', 'workspace' => $this->workspace, 'client' => $this->client, 'status' => ComplianceFolderStatus::APPROVED, 'documents' => new ArrayCollection()]);

        $checklist = $this->useCase(DerStatement::VERSION, null)($folder);

        self::assertSame(['DER accusé par le client', 'Pièces justificatives validées', 'Décision de conformité'], array_map(static fn (FolderChecklistItem $item): string => $item->label, $checklist->items));
        self::assertTrue($checklist->items[2]->done);
    }

    private function useCase(string $derVersion, ?AdequacyReport $report, ?ElectronicDeliveryConsent $amendment = null): GetFolderChecklistUseCase
    {
        $acknowledgement = $this->createEntityState(DerAcknowledgement::class, [
            'slugId' => 'der_ack_1',
            'acknowledgedAt' => new \DateTimeImmutable('2026-11-01'),
            'statementVersion' => $derVersion,
            'revokedAt' => null,
        ]);
        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $documentRepo->method('findDerByFolder')->willReturn($this->createEntityState(ComplianceDocument::class, ['acknowledgements' => new ArrayCollection([$acknowledgement])]));

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn($this->profile);

        // Une vérification ne peut plus être enregistrée sur un profil révoqué.
        $checks = $this->profile->isInForce() ? [$this->check()] : [];
        $checkRepo = $this->createStub(ProductAdequacyCheckRepositoryInterface::class);
        $checkRepo->method('findByFolder')->willReturnCallback(fn (ComplianceFolder $folder): array => $folder === $this->folder ? $checks : []);

        $reportRepo = $this->createStub(AdequacyReportRepositoryInterface::class);
        $reportRepo->method('findInForceByFolder')->willReturn($report);

        $consentRepo = $this->createStub(ElectronicDeliveryConsentRepositoryInterface::class);
        $consentRepo->method('findLatestByFolder')->willReturn($amendment);

        return new GetFolderChecklistUseCase(
            new ElectronicDeliveryConsentResolver($documentRepo, $consentRepo),
            $this->createStub(ClientFileRepositoryInterface::class),
            $profileRepo,
            $checkRepo,
            $reportRepo,
        );
    }
}
