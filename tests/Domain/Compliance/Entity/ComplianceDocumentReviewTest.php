<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Entity;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentRejectionReason;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Décision du conseiller sur une pièce : valider, refuser (motif), annuler, signaler au client.
 */
final class ComplianceDocumentReviewTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string ADVISOR = 'Camille Martin';

    private IndividualFolder $folder;

    protected function setUp(): void
    {
        $this->folder = $this->createEntityState(IndividualFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'status' => ComplianceFolderStatus::IN_REVIEW,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function document(array $overrides = []): ComplianceDocument
    {
        $document = $this->createEntityState(ComplianceDocument::class, array_merge([
            'slugId' => 'comp_doc_' . random_int(1, 99999),
            'folder' => $this->folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'kyc/doc.pdf',
            'isMandatory' => true,
            'acknowledgements' => new ArrayCollection(),
        ], $overrides));
        $this->folder->documents->add($document);

        return $document;
    }

    public function testValidationRecordsWhoAndWhen(): void
    {
        $document = $this->document();

        $document->validateByAdvisor(self::ADVISOR);

        self::assertSame(DocumentStatus::VALID, $document->status);
        self::assertSame(self::ADVISOR, $document->reviewedByName);
        self::assertInstanceOf(\DateTimeImmutable::class, $document->reviewedAt);
        self::assertTrue($document->isReceived());
        self::assertCount(1, $this->folder->history);
    }

    public function testRejectionKeepsAClientReadableReasonAndStaysInternal(): void
    {
        $document = $this->document();

        $document->rejectByAdvisor(DocumentRejectionReason::TOO_OLD, '  Merci de fournir une facture récente.  ', self::ADVISOR);

        self::assertSame(DocumentStatus::REJECTED, $document->status);
        self::assertSame(DocumentRejectionReason::TOO_OLD, $document->rejectionReasonCode);
        self::assertSame("Le justificatif date de plus de 3 mois.\nMerci de fournir une facture récente.", $document->rejectionReason);
        self::assertTrue($document->isRejectionPendingNotice());
        self::assertFalse($document->isRejectionVisibleToClient(), 'tant qu\'il n\'est pas signalé, le refus reste interne');
        self::assertTrue($document->isAskToClient, 'une pièce refusée est redemandée au client');
    }

    public function testOtherReasonRequiresAComment(): void
    {
        $this->expectException(\DomainException::class);

        $this->document()->rejectByAdvisor(DocumentRejectionReason::OTHER, '   ', self::ADVISOR);
    }

    public function testOtherReasonUsesTheCommentAlone(): void
    {
        $document = $this->document();

        $document->rejectByAdvisor(DocumentRejectionReason::OTHER, 'Merci de transmettre la page 2.', self::ADVISOR);

        self::assertSame('Merci de transmettre la page 2.', $document->rejectionReason);
    }

    public function testTheCommentIsBounded(): void
    {
        $document = $this->document();

        $document->rejectByAdvisor(DocumentRejectionReason::OTHER, str_repeat('a', 2000), self::ADVISOR);

        self::assertSame(500, mb_strlen((string) $document->rejectionReason));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('notReviewable')]
    public function testOnlyAReceivedUndecidedPieceCanBeDecided(array $overrides): void
    {
        $document = $this->document($overrides);
        self::assertFalse($document->awaitsAdvisorReview());

        $this->expectException(\DomainException::class);

        $document->validateByAdvisor(self::ADVISOR);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notReviewable(): iterable
    {
        yield 'rien reçu' => [['status' => DocumentStatus::PENDING, 'storagePath' => null]];
        yield 'en cours de traitement' => [['status' => DocumentStatus::PROCESSING]];
        yield 'déjà validée' => [['status' => DocumentStatus::VALID]];
        yield 'déjà refusée' => [['status' => DocumentStatus::REJECTED]];
        yield 'DER' => [['type' => DocumentType::DER, 'status' => DocumentStatus::UPLOADED]];
    }

    public function testAValidationCanBeCancelledWithAReason(): void
    {
        $document = $this->document();
        $document->validateByAdvisor(self::ADVISOR);

        $cancelled = $document->cancelReview('Validée par erreur', self::ADVISOR);

        self::assertSame(DocumentStatus::VALID, $cancelled);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->reviewedAt);
        self::assertTrue($document->awaitsAdvisorReview());
    }

    public function testCancellingRequiresAReason(): void
    {
        $document = $this->document();
        $document->validateByAdvisor(self::ADVISOR);

        $this->expectException(\DomainException::class);

        $document->cancelReview('  ', self::ADVISOR);
    }

    public function testARejectionAlreadySentToTheClientCanNoLongerBeCancelled(): void
    {
        $document = $this->document();
        $document->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);
        self::assertTrue($document->canCancelReview());

        $document->markCorrectionRequested();

        self::assertFalse($document->canCancelReview());
        $this->expectException(\DomainException::class);
        $document->cancelReview('Erreur', self::ADVISOR);
    }

    public function testCorrectionNoticeIsMarkedOnlyOnce(): void
    {
        $document = $this->document();
        $document->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);

        self::assertTrue($document->markCorrectionRequested());
        self::assertFalse($document->markCorrectionRequested(), 'jamais deux signalements pour le même refus');
        self::assertTrue($document->isRejectionVisibleToClient());
    }

    public function testANewFileWipesThePreviousDecision(): void
    {
        $document = $this->document();
        $document->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);
        $document->markCorrectionRequested();

        $document->markAsUploaded('kyc/nouveau.pdf', 'nouveau.pdf', 'application/pdf', 10);

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->rejectionReason);
        self::assertNull($document->rejectionReasonCode);
        self::assertNull($document->reviewedAt);
        self::assertNull($document->correctionRequestedAt);
        self::assertTrue($document->awaitsAdvisorReview());
    }

    public function testCorrectionsRequestGroupsEveryUnsentRejectionOnce(): void
    {
        $illegible = $this->document();
        $tooOld = $this->document(['type' => DocumentType::ID_CARD]);
        $alreadySent = $this->document(['type' => DocumentType::RIB]);
        $validated = $this->document(['type' => DocumentType::KBIS]);

        $alreadySent->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);
        $alreadySent->markCorrectionRequested();
        $illegible->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);
        $tooOld->rejectByAdvisor(DocumentRejectionReason::EXPIRED, null, self::ADVISOR);
        $validated->validateByAdvisor(self::ADVISOR);

        $notified = $this->folder->requestCorrectionsFromClient(self::ADVISOR);

        self::assertSame([$illegible, $tooOld], $notified);
        self::assertSame(ComplianceFolderStatus::NEEDS_CORRECTION, $this->folder->status);
        self::assertSame([], $this->folder->rejectionsPendingNotice());
        self::assertTrue($this->folder->canReceiveClientDocuments(), 'le client peut redéposer');
    }

    public function testCorrectionsRequestTwiceInARowIsRefused(): void
    {
        $this->document()->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);
        $this->folder->requestCorrectionsFromClient(self::ADVISOR);

        $this->expectException(\DomainException::class);

        $this->folder->requestCorrectionsFromClient(self::ADVISOR);
    }

    #[DataProvider('closedFolders')]
    public function testAClosedFolderIsFrozen(ComplianceFolderStatus $status): void
    {
        $this->folder = $this->createEntityState(IndividualFolder::class, [
            'slugId' => 'comp_fol_2',
            'reference' => 'DOS-2',
            'status' => $status,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
        $this->document()->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, self::ADVISOR);

        self::assertFalse($this->folder->isOpenForDocumentReview());
        $this->expectException(\DomainException::class);

        $this->folder->requestCorrectionsFromClient(self::ADVISOR);
    }

    /**
     * @return iterable<string, array{ComplianceFolderStatus}>
     */
    public static function closedFolders(): iterable
    {
        yield 'approuvé' => [ComplianceFolderStatus::APPROVED];
        yield 'rejeté' => [ComplianceFolderStatus::REJECTED];
        yield 'archivé' => [ComplianceFolderStatus::ARCHIVED];
    }
}
