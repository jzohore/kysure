<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr\Handler;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrProcessedEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Kyc\Validator\DocumentValidator;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Port\OcrProviderInterface;
use App\Infrastructure\Ocr\Handler\ProcessOcrMessageHandler;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class ProcessOcrMessageHandlerTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string DOCUMENT_ID = '0192f0a0-0000-7000-8000-000000000001';

    private ComplianceDocumentRepositoryInterface&MockObject $repository;
    private OcrProviderInterface&Stub $ocrProvider;
    private DocumentStorageInterface&Stub $storage;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ProcessOcrMessageHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->ocrProvider = $this->createStub(OcrProviderInterface::class);
        $this->storage = $this->createStub(DocumentStorageInterface::class);
        $this->storage->method('getTemporaryUrl')->willReturn('https://s3.test/tmp/doc.pdf');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->handler = $this->handlerWith($this->ocrProvider);
    }

    private function handlerWith(OcrProviderInterface $ocrProvider): ProcessOcrMessageHandler
    {
        return new ProcessOcrMessageHandler(
            $this->repository,
            $ocrProvider,
            $this->storage,
            new DocumentValidator(),
            $this->eventDispatcher,
            new NullLogger(),
        );
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
            'id' => Uuid::fromString(self::DOCUMENT_ID),
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'documents/kyc/comp_fol_1/justif.pdf',
            'acknowledgements' => new ArrayCollection(),
        ], $overrides));
    }

    // --- Chemin nominal : extraction OK ----------------------------------------

    public function testAttachesOcrDataAndKeepsDocumentUploadedWhenNoFinding(): void
    {
        $document = $this->document();
        $this->repository->method('findById')->willReturn($document);
        // Justificatif lisible et récent : DocumentValidator ne remonte rien.
        $extracted = [
            'holder_name' => 'Jean Dupont',
            'address' => '12 rue des Lilas 75011 Paris',
            'document_date' => new \DateTimeImmutable('-1 month')->format('Y-m-d'),
        ];
        $this->ocrProvider->method('extractData')->willReturn($extracted);

        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DocumentOcrProcessedEvent $e): bool => $e->document === $document
                && $e->extractionSucceeded
                && [] === $e->findings))
            ->willReturnArgument(0);

        ($this->handler)(new ProcessOcrMessage(self::DOCUMENT_ID));

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertSame($extracted, $document->ocrData);
        self::assertNull($document->ocrFindings);
        self::assertSame(DocumentValidator::VERSION, $document->ocrValidatorVersion);
        self::assertNull($document->rejectionReason);
    }

    public function testAttachesFindingsButNeverRejectsWhenValidatorRaisesAnomalies(): void
    {
        $document = $this->document();
        $this->repository->method('findById')->willReturn($document);
        // Ni adresse ni date : DocumentValidator produit des points de vigilance.
        $extracted = ['holder_name' => 'Jean Dupont'];
        $this->ocrProvider->method('extractData')->willReturn($extracted);

        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DocumentOcrProcessedEvent $e): bool => $e->extractionSucceeded
                && [] !== $e->findings))
            ->willReturnArgument(0);

        ($this->handler)(new ProcessOcrMessage(self::DOCUMENT_ID));

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNotSame(DocumentStatus::REJECTED, $document->status);
        self::assertSame($extracted, $document->ocrData);
        self::assertNotNull($document->ocrFindings);
        self::assertNotEmpty($document->ocrFindings);
        self::assertSame(DocumentValidator::VERSION, $document->ocrValidatorVersion);
        self::assertNull($document->rejectionReason);
    }

    // --- Extraction impossible / vide : signalée, jamais de retry ni de rejet --

    public function testSignalsWithoutRetryWhenExtractionThrowsDomainException(): void
    {
        $document = $this->document();
        $this->repository->method('findById')->willReturn($document);
        $this->ocrProvider->method('extractData')
            ->willThrowException(new \DomainException('Type de document non supporté.'));

        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DocumentOcrProcessedEvent $e): bool => false === $e->extractionSucceeded
                && 1 === count($e->findings)))
            ->willReturnArgument(0);

        // Le handler ne relance pas l'exception (sinon Messenger déclencherait un retry).
        ($this->handler)(new ProcessOcrMessage(self::DOCUMENT_ID));

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->ocrData);
        self::assertNotNull($document->ocrFindings);
        self::assertCount(1, $document->ocrFindings);
        self::assertSame(DocumentValidator::VERSION, $document->ocrValidatorVersion);
    }

    public function testSignalsWhenExtractionReturnsOnlyEmptyValues(): void
    {
        $document = $this->document();
        $this->repository->method('findById')->willReturn($document);
        $this->ocrProvider->method('extractData')->willReturn(['last_name' => '', 'mrz' => null]);

        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DocumentOcrProcessedEvent $e): bool => false === $e->extractionSucceeded
                && 1 === count($e->findings)))
            ->willReturnArgument(0);

        ($this->handler)(new ProcessOcrMessage(self::DOCUMENT_ID));

        self::assertSame(DocumentStatus::UPLOADED, $document->status);
        self::assertNull($document->ocrData);
        self::assertNotNull($document->ocrFindings);
        self::assertCount(1, $document->ocrFindings);
    }

    // --- Idempotence : garde en tête de handler ------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function alreadyProcessedProvider(): iterable
    {
        yield 'données OCR déjà présentes' => [['ocrData' => ['first_name' => 'Jean']]];
        yield 'points de vigilance déjà présents' => [['ocrFindings' => ['un point de vigilance']]];
        yield 'décision humaine : validé' => [['status' => DocumentStatus::VALID]];
        yield 'décision humaine : rejeté' => [['status' => DocumentStatus::REJECTED]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('alreadyProcessedProvider')]
    public function testReturnsImmediatelyWithoutCallingOcrWhenDocumentAlreadyProcessed(array $overrides): void
    {
        $document = $this->document($overrides);
        $this->repository->method('findById')->willReturn($document);

        $ocrProvider = $this->createMock(OcrProviderInterface::class);
        $ocrProvider->expects($this->never())->method('extractData');
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handlerWith($ocrProvider))(new ProcessOcrMessage(self::DOCUMENT_ID));
    }

    public function testDoesNotCallOcrForATypeWithoutAutomaticAnalysis(): void
    {
        $document = $this->document(['type' => DocumentType::RBE]);
        $this->repository->method('findById')->willReturn($document);

        $ocrProvider = $this->createMock(OcrProviderInterface::class);
        $ocrProvider->expects($this->never())->method('extractData');
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handlerWith($ocrProvider))(new ProcessOcrMessage(self::DOCUMENT_ID));
    }

    public function testReplayedAfterAnUnavailableSignalAnalysesTheFileAgain(): void
    {
        $document = $this->document([
            'ocrFindings' => ['L\'analyse automatique est momentanément indisponible.'],
            'ocrUnavailableAt' => new \DateTimeImmutable('-1 hour'),
        ]);
        $this->repository->method('findById')->willReturn($document);
        $this->ocrProvider->method('extractData')->willReturn(['holder_name' => 'Jean Dupont']);

        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())->method('dispatch')->willReturnArgument(0);

        ($this->handler)(new ProcessOcrMessage(self::DOCUMENT_ID));

        self::assertNull($document->ocrUnavailableAt);
        self::assertSame(['holder_name' => 'Jean Dupont'], $document->ocrData);
    }

    public function testThrowsWhenDocumentIsUnknown(): void
    {
        $this->repository->expects($this->once())->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');

        $ocrProvider = $this->createMock(OcrProviderInterface::class);
        $ocrProvider->expects($this->never())->method('extractData');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\InvalidArgumentException::class);
        ($this->handlerWith($ocrProvider))(new ProcessOcrMessage(self::DOCUMENT_ID));
    }
}
