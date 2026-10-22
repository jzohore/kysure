<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Entity;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Enum\OcrAnalysisState;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ComplianceDocumentOcrAnalysisTest extends TestCase
{
    use ReflectionHelperTrait;

    /**
     * @param array<string, mixed> $overrides
     */
    private function document(array $overrides = []): ComplianceDocument
    {
        $folder = $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'history' => [],
        ]);

        return $this->createEntityState(ComplianceDocument::class, array_merge([
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'status' => DocumentStatus::UPLOADED,
            'acknowledgements' => new ArrayCollection(),
        ], $overrides));
    }

    public function testStoresExtractedDataWithoutFindingsAndKeepsDocumentUploaded(): void
    {
        $document = $this->document(['status' => DocumentStatus::PROCESSING]);
        $data = ['first_name' => 'Jean', 'date_of_expiry' => '2999-12-31'];

        $document->attachOcrAnalysis($data, [], 'v1');

        self::assertSame($data, $document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertSame('v1', $document->ocrValidatorVersion);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->rejectionReason);
        self::assertCount(1, $document->folder->history);
    }

    public function testStoresFindingsAndNullDataWhenExtractionFailed(): void
    {
        $document = $this->document();

        $document->attachOcrAnalysis(null, ['a', 'b'], 'v1');

        self::assertSame(['a', 'b'], $document->ocrFindings);
        self::assertNull($document->ocrData);
        self::assertSame('v1', $document->ocrValidatorVersion);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
    }

    public function testEmptyFindingsListIsNormalizedToNull(): void
    {
        $document = $this->document();

        $document->attachOcrAnalysis(['x' => 'y'], []);

        self::assertNull($document->ocrFindings);
        self::assertNull($document->ocrValidatorVersion);
    }

    public function testReplacingTheFileInvalidatesThePreviousAnalysis(): void
    {
        $document = $this->document(['storagePath' => 'old.pdf']);
        $document->attachOcrAnalysis(['holder_name' => 'Ancien titulaire'], ['ancien point'], 'v1');
        self::assertFalse($document->needsOcrAnalysis());

        $document->markAsUploaded('new.pdf', 'new.pdf', 'application/pdf', 100);

        self::assertNull($document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertNull($document->ocrValidatorVersion);
        self::assertTrue($document->needsOcrAnalysis());
    }

    public function testAnAnalysedFileIsNotAnalysedAgain(): void
    {
        $document = $this->document(['storagePath' => 'doc.pdf']);
        self::assertTrue($document->needsOcrAnalysis());

        $document->attachOcrAnalysis(null, ['Aucune donnée n\'a pu être extraite.'], 'v1');

        self::assertFalse($document->needsOcrAnalysis());
    }

    public function testAHumanDecisionIsNeverOverwrittenByANewAnalysisRequest(): void
    {
        self::assertFalse($this->document(['storagePath' => 'doc.pdf', 'status' => DocumentStatus::VALID])->needsOcrAnalysis());
        self::assertFalse($this->document(['storagePath' => 'doc.pdf', 'status' => DocumentStatus::REJECTED])->needsOcrAnalysis());
    }

    public function testATypeWithoutAutomaticAnalysisNeverNeedsOcr(): void
    {
        self::assertFalse($this->document(['storagePath' => 'doc.pdf', 'type' => DocumentType::RBE])->needsOcrAnalysis());
    }

    public function testMarkOcrUnavailableLeavesAVisibleSignalAndStaysUploaded(): void
    {
        $document = $this->document(['storagePath' => 'doc.pdf']);

        self::assertTrue($document->markOcrUnavailable());

        self::assertNotNull($document->ocrUnavailableAt);
        self::assertNotEmpty($document->ocrFindings);
        self::assertNull($document->ocrData);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        // Le signal laisse la porte ouverte à un rejeu (messenger:failed:retry).
        self::assertTrue($document->needsOcrAnalysis());
        self::assertCount(1, $document->folder->history);
    }

    public function testMarkOcrUnavailableIsIdempotent(): void
    {
        $document = $this->document(['storagePath' => 'doc.pdf']);
        $document->markOcrUnavailable();
        $firstSignalAt = $document->ocrUnavailableAt;

        self::assertFalse($document->markOcrUnavailable());

        self::assertSame($firstSignalAt, $document->ocrUnavailableAt);
        self::assertCount(1, $document->folder->history);
    }

    public function testMarkOcrUnavailableNeverReplacesAnAnalysisThatSucceeded(): void
    {
        $document = $this->document(['storagePath' => 'doc.pdf']);
        $document->attachOcrAnalysis(['holder_name' => 'Jean'], [], 'v1');

        self::assertFalse($document->markOcrUnavailable());

        self::assertSame(['holder_name' => 'Jean'], $document->ocrData);
        self::assertNull($document->ocrUnavailableAt);
    }

    public function testASuccessfulReplayClearsTheUnavailableSignal(): void
    {
        $document = $this->document(['storagePath' => 'doc.pdf']);
        $document->markOcrUnavailable();

        $document->attachOcrAnalysis(['holder_name' => 'Jean'], [], 'v1');

        self::assertNull($document->ocrUnavailableAt);
        self::assertSame(OcrAnalysisState::COMPLETED, $document->ocrAnalysisState());
    }

    public function testAnalysisStateForTheCgp(): void
    {
        self::assertSame(OcrAnalysisState::NOT_APPLICABLE, $this->document(['status' => DocumentStatus::PENDING])->ocrAnalysisState());
        self::assertSame(OcrAnalysisState::MANUAL_CHECK, $this->document(['type' => DocumentType::RBE])->ocrAnalysisState());
        self::assertSame(
            OcrAnalysisState::IN_PROGRESS,
            $this->document(['uploadedAt' => new \DateTimeImmutable('-2 minutes')])->ocrAnalysisState(),
        );
    }

    public function testAPieceStillWithoutAnalysisLongAfterTheUploadIsShownAsUnavailable(): void
    {
        // Message perdu / worker arrêté : jamais de « en cours » éternel.
        $document = $this->document(['uploadedAt' => new \DateTimeImmutable('-3 hours')]);

        self::assertSame(OcrAnalysisState::UNAVAILABLE, $document->ocrAnalysisState());
    }

    public function testClearsAnyPreviousRejectionReason(): void
    {
        $document = $this->document([
            'status' => DocumentStatus::REJECTED,
            'rejectionReason' => 'Ancien motif humain',
        ]);

        $document->attachOcrAnalysis(['x' => 'y'], ['point de vigilance'], 'v1');

        self::assertNull($document->rejectionReason);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
    }

    public function testManualRerunDiscardsThePreviousAnalysisAndShowsItInProgress(): void
    {
        $uploadedAt = new \DateTimeImmutable('-3 days');
        $document = $this->document([
            'storagePath' => 'kyc/doc.pdf',
            'uploadedAt' => $uploadedAt,
            'ocrData' => ['holder_name' => 'MARTIN'],
            'ocrFindings' => ['Le justificatif date du 12/01/2026 : il a plus de 3 mois.'],
            'ocrValidatorVersion' => 'v1',
        ]);
        self::assertSame(OcrAnalysisState::COMPLETED, $document->ocrAnalysisState());

        $document->requestOcrRerun();

        self::assertNull($document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertNull($document->ocrValidatorVersion);
        self::assertTrue($document->needsOcrAnalysis());
        // Pièce déposée il y a 3 jours : sans l'horodatage de relance, elle s'afficherait « indisponible ».
        self::assertSame(OcrAnalysisState::IN_PROGRESS, $document->ocrAnalysisState());
        // La date légale du dépôt n'est jamais touchée.
        self::assertSame($uploadedAt, $document->uploadedAt);
        self::assertCount(1, $document->folder->history);
    }

    public function testManualRerunIsPossibleAfterAnUnavailableSignal(): void
    {
        $document = $this->document(['storagePath' => 'kyc/doc.pdf', 'uploadedAt' => new \DateTimeImmutable('-3 hours')]);
        $document->markOcrUnavailable();
        self::assertSame(OcrAnalysisState::UNAVAILABLE, $document->ocrAnalysisState());

        $document->requestOcrRerun();

        self::assertSame(OcrAnalysisState::IN_PROGRESS, $document->ocrAnalysisState());
    }

    public function testManualRerunIsRefusedWhileAnAnalysisIsAlreadyRunning(): void
    {
        // Double clic : une seule analyse (Textract est facturé à la page).
        $document = $this->document(['storagePath' => 'kyc/doc.pdf', 'uploadedAt' => new \DateTimeImmutable('-2 minutes')]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('déjà en cours');

        $document->requestOcrRerun();
    }

    public function testManualRerunTwiceInARowIsRefused(): void
    {
        $document = $this->document(['storagePath' => 'kyc/doc.pdf', 'uploadedAt' => new \DateTimeImmutable('-3 days'), 'ocrData' => ['x' => 'y']]);
        $document->requestOcrRerun();

        $this->expectException(\DomainException::class);

        $document->requestOcrRerun();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonRerunnableDocuments')]
    public function testManualRerunIsRefusedForNonAnalysableOrAlreadyDecidedPieces(array $overrides): void
    {
        $document = $this->document($overrides + ['uploadedAt' => new \DateTimeImmutable('-3 days'), 'ocrData' => ['x' => 'y']]);

        $this->expectException(\DomainException::class);

        $document->requestOcrRerun();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonRerunnableDocuments(): iterable
    {
        yield 'aucun fichier reçu' => [['storagePath' => null]];
        yield 'pièce validée par un humain' => [['storagePath' => 'kyc/doc.pdf', 'status' => DocumentStatus::VALID]];
        yield 'pièce rejetée par un humain' => [['storagePath' => 'kyc/doc.pdf', 'status' => DocumentStatus::REJECTED]];
        yield 'type sans OCR' => [['storagePath' => 'kyc/doc.pdf', 'type' => DocumentType::RBE]];
    }

    public function testAFinishedAnalysisIsDatedForTheAdvisor(): void
    {
        $document = $this->document(['storagePath' => 'kyc/doc.pdf']);
        self::assertNull($document->ocrAnalyzedAt);

        $before = new \DateTimeImmutable();
        $document->attachOcrAnalysis(['holder_name' => 'MARTIN'], [], 'v1');

        self::assertInstanceOf(\DateTimeImmutable::class, $document->ocrAnalyzedAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $document->ocrAnalyzedAt->getTimestamp());
    }

    public function testTheAnalysisDateNeverOutlivesTheDataItDescribes(): void
    {
        $document = $this->document(['storagePath' => 'kyc/doc.pdf', 'uploadedAt' => new \DateTimeImmutable('-3 days')]);
        $document->attachOcrAnalysis(['holder_name' => 'MARTIN'], [], 'v1');

        $document->requestOcrRerun();
        self::assertNull($document->ocrAnalyzedAt, 'relance : l\'ancienne date disparaît avec l\'ancienne analyse');

        $document->attachOcrAnalysis(['holder_name' => 'MARTIN'], [], 'v1');
        $document->markAsUploaded('kyc/nouveau.pdf', 'nouveau.pdf', 'application/pdf', 10);
        self::assertNull($document->ocrAnalyzedAt, 'nouveau fichier : pas de date d\'une analyse qui ne le concerne pas');
    }

    public function testAnUnavailableSignalIsNotAnAnalysisDate(): void
    {
        $document = $this->document(['storagePath' => 'kyc/doc.pdf', 'uploadedAt' => new \DateTimeImmutable('-3 hours')]);

        $document->markOcrUnavailable();

        self::assertNull($document->ocrAnalyzedAt);
    }
}
