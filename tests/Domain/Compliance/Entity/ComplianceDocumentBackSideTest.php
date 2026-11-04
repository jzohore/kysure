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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ComplianceDocumentBackSideTest extends TestCase
{
    use ClockSensitiveTrait;
    use ReflectionHelperTrait;

    protected function setUp(): void
    {
        self::mockTime('2026-11-02 10:00:00');
    }

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
            'type' => DocumentType::ID_CARD,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'compliance_folders/comp_fol_1/recto.jpg',
            'uploadedAt' => new \DateTimeImmutable('2026-11-02 09:00:00'),
            'acknowledgements' => new ArrayCollection(),
        ], $overrides));
    }

    public function testOnlyTheIdCardHasABackSide(): void
    {
        self::assertTrue(DocumentType::ID_CARD->hasBackSide());
        self::assertFalse(DocumentType::PASSPORT->hasBackSide());
        self::assertFalse(DocumentType::PROOF_OF_ADDRESS->hasBackSide());
    }

    public function testTheBackSideCanOnlyFollowTheFrontSide(): void
    {
        self::assertTrue($this->document()->canReceiveBackSide());
        self::assertFalse($this->document(['storagePath' => null, 'status' => DocumentStatus::PENDING])->canReceiveBackSide());
        self::assertFalse($this->document(['type' => DocumentType::PASSPORT])->canReceiveBackSide());
    }

    public function testStoresTheBackSideAndKeepsTheFrontFile(): void
    {
        $document = $this->document(['status' => DocumentStatus::PROCESSING]);

        $document->markBackAsUploaded('compliance_folders/comp_fol_1/verso.jpg', 'image/jpeg', 2048);

        self::assertSame('compliance_folders/comp_fol_1/verso.jpg', $document->backStoragePath);
        self::assertSame('image/jpeg', $document->backMimeType);
        self::assertSame(2048, $document->backSize);
        self::assertSame('compliance_folders/comp_fol_1/recto.jpg', $document->storagePath);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertCount(1, $document->folder->history);
    }

    public function testTheBackSideInvalidatesThePreviousAnalysisAndRestartsIt(): void
    {
        $document = $this->document();
        $document->attachOcrAnalysis(['last_name' => 'DUPONT'], ['Bande de lecture optique (MRZ) illisible ou absente.'], 'v1');
        self::assertSame(OcrAnalysisState::COMPLETED, $document->ocrAnalysisState());

        $document->markBackAsUploaded('compliance_folders/comp_fol_1/verso.jpg', 'image/jpeg', 2048);

        self::assertNull($document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertTrue($document->needsOcrAnalysis());
        self::assertSame(OcrAnalysisState::IN_PROGRESS, $document->ocrAnalysisState());
        // La date légale du dépôt reste celle du recto.
        self::assertEquals(new \DateTimeImmutable('2026-11-02 09:00:00'), $document->uploadedAt);
    }

    public function testTheBackSideIsRefusedBeforeTheFrontSide(): void
    {
        $document = $this->document(['storagePath' => null, 'status' => DocumentStatus::PENDING]);

        $this->expectException(\DomainException::class);

        $document->markBackAsUploaded('compliance_folders/comp_fol_1/verso.jpg', 'image/jpeg', 2048);
    }

    public function testTheBackSideIsRefusedOnASingleSidedDocument(): void
    {
        $document = $this->document(['type' => DocumentType::PASSPORT]);

        $this->expectException(\DomainException::class);

        $document->markBackAsUploaded('compliance_folders/comp_fol_1/verso.jpg', 'image/jpeg', 2048);
    }

    public function testAFailedBackSideStorageLeavesTheDocumentReceived(): void
    {
        $document = $this->document(['status' => DocumentStatus::PROCESSING]);

        $document->markAsUploadFailed();

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->backStoragePath);
    }
}
