<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance\UseCase\ComplianceDocument;

use App\Application\Compliance\DTO\Request\UploadDocumentRequest;
use App\Application\Compliance\UseCase\ComplianceDocument\UploadDocumentUseCase;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\ClientDocumentUploadedEvent;
use App\Domain\Compliance\Event\DocumentMalwareDetectedEvent;
use App\Domain\Compliance\Event\DocumentReceivedLocalEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Exception\Document\MalwareDetectedException;
use App\Domain\Compliance\Exception\Document\VirusScannerUnavailableException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Port\VirusScannerInterface;
use App\Infrastructure\Service\StorageService;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class UploadDocumentUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private ComplianceDocumentRepositoryInterface&MockObject $documentRepository;
    private ComplianceFolderRepositoryInterface&Stub $folderRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private VirusScannerInterface&MockObject $virusScanner;
    private UploadDocumentUseCase $useCase;
    private string $tempDir;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->virusScanner = $this->createMock(VirusScannerInterface::class);

        $this->tempDir = sys_get_temp_dir() . '/kysure_upload_test_' . bin2hex(random_bytes(4));
        $this->uploadsDir = $this->tempDir . '/incoming';
        mkdir($this->uploadsDir, 0o755, true);

        $this->useCase = new UploadDocumentUseCase(
            $this->documentRepository,
            $this->folderRepository,
            $this->eventDispatcher,
            new StorageService(),
            $this->virusScanner,
            $this->tempDir . '/stored',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/{incoming,stored}/*', \GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach ([$this->uploadsDir, $this->tempDir . '/stored', $this->tempDir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function folder(ComplianceFolderStatus $status = ComplianceFolderStatus::AWAITING_CLIENT): BusinessFolder
    {
        return $this->createEntityState(BusinessFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-018',
            'status' => $status,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
    }

    private function askedDocument(BusinessFolder $folder, DocumentType $type = DocumentType::KBIS): ComplianceDocument
    {
        $folder->requireDocument($type, isMandatory: true);
        $folder->requestMandatoryDocumentsFromClient();

        $document = $folder->documents->last();
        $this->setId($document);

        return $document;
    }

    private function setId(ComplianceDocument $document): void
    {
        new \ReflectionProperty(ComplianceDocument::class, 'id')->setValue($document, Uuid::v7());
    }

    private function request(BusinessFolder $folder, ComplianceDocument $document, string $content = "%PDF-1.7\nKBIS"): UploadDocumentRequest
    {
        $path = $this->uploadsDir . '/' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, $content);

        $request = new UploadDocumentRequest();
        $request->folderId = (string) $folder->id;
        $request->documentId = (string) $document->id;
        $request->file = new UploadedFile($path, 'kbis.pdf', 'application/pdf', null, true);

        return $request;
    }

    private function wire(BusinessFolder $folder, ?ComplianceDocument $document): void
    {
        $this->folderRepository->method('findById')->willReturn($folder);
        $this->documentRepository->method('findById')->willReturn($document);
    }

    public function testStoresTheFileAndTracesTheDepositWithTheFileFingerprint(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        $this->wire($folder, $document);
        $content = "%PDF-1.7\nKBIS";

        $this->virusScanner->expects($this->once())->method('scan');
        $this->documentRepository->expects($this->once())->method('save')->with($document);

        $dispatched = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        ($this->useCase)($this->request($folder, $document, $content));

        self::assertSame(DocumentStatus::PROCESSING, $document->status);

        $uploaded = $dispatched[0];
        self::assertInstanceOf(ClientDocumentUploadedEvent::class, $uploaded);
        self::assertSame('comp_fol_1', $uploaded->getFolderSlugId());
        self::assertSame('kbis', $uploaded->getDocumentType());
        self::assertSame(hash('sha256', $content), $uploaded->getSha256());
        self::assertSame(strlen($content), $uploaded->getSize());
        self::assertSame('application/pdf', $uploaded->getMimeType());

        self::assertInstanceOf(DocumentReceivedLocalEvent::class, $dispatched[1]);
    }

    public function testAnInfectedFileIsRefusedTracedAndNeverStored(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        $this->wire($folder, $document);
        $content = 'X5O!P%@AP-fake-eicar';

        $this->virusScanner->expects($this->once())->method('scan')->willThrowException(new MalwareDetectedException('Eicar-Test-Signature'));
        $this->documentRepository->expects($this->never())->method('save');

        $dispatched = [];
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        try {
            ($this->useCase)($this->request($folder, $document, $content));
            self::fail('Un fichier infecté doit être refusé.');
        } catch (MalwareDetectedException) {
            // attendu
        }

        self::assertSame(DocumentStatus::PENDING, $document->status, 'La pièce reste à déposer.');
        $event = $dispatched[0];
        self::assertInstanceOf(DocumentMalwareDetectedEvent::class, $event);
        self::assertSame('Eicar-Test-Signature', $event->getSignature());
        self::assertSame(hash('sha256', $content), $event->getSha256());
        self::assertSame('kbis', $event->getDocumentType());
        self::assertSame([], glob($this->tempDir . '/stored/*') ?: [], 'Aucun fichier infecté n\'est conservé.');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAFileThatCouldNotBeScannedIsRefused(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        $this->wire($folder, $document);

        $this->virusScanner->method('scan')->willThrowException(new VirusScannerUnavailableException());
        $this->documentRepository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(VirusScannerUnavailableException::class);
        ($this->useCase)($this->request($folder, $document));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesADepositWhenTheCollectionIsClosed(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::IN_REVIEW);
        $document = $this->askedDocument($folder);
        $this->wire($folder, $document);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->request($folder, $document));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesADocumentThatWasNeverRequestedFromTheClient(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::FINANCIAL_STATEMENTS, isMandatory: false);
        $document = $folder->documents->first();
        $this->setId($document);
        $this->wire($folder, $document);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->request($folder, $document));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesADoubleSubmissionWhileTheFirstFileIsBeingProcessed(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        $document->markAsProcessed();
        $this->wire($folder, $document);

        $this->documentRepository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->request($folder, $document));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesToReplaceADocumentAlreadyValidatedByTheFirm(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        new \ReflectionProperty(ComplianceDocument::class, 'status')->setValue($document, DocumentStatus::VALID);
        $this->wire($folder, $document);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($this->request($folder, $document));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsTheClientToResendARejectedDocument(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::NEEDS_CORRECTION);
        $document = $this->askedDocument($folder);
        $document->reject('Document illisible');
        $this->wire($folder, $document);

        $this->eventDispatcher->expects($this->exactly(2))->method('dispatch');

        ($this->useCase)($this->request($folder, $document));

        self::assertSame(DocumentStatus::PROCESSING, $document->status);
        self::assertNull($document->rejectionReason);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesADocumentBelongingToAnotherFolder(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $foreignDocument = $this->askedDocument($otherFolder);
        $this->wire($folder, $foreignDocument);

        $this->expectException(InvalidDocumentFolderException::class);
        ($this->useCase)($this->request($folder, $foreignDocument));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesAnUnknownDocument(): void
    {
        $folder = $this->folder();
        $document = $this->askedDocument($folder);
        $this->wire($folder, null);

        $this->expectException(DocumentNotFoundException::class);
        ($this->useCase)($this->request($folder, $document));
    }
}
