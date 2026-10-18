<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentReceivedLocalEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\Compliance\Listener\DocumentReceivedListener;
use App\Infrastructure\KYC\Handler\ProcessAndStoreKycDocumentMessageHandler;
use App\Infrastructure\KYC\Message\ProcessAndStoreKycDocumentMessage;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class DocumentReceivedListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private ProcessAndStoreKycDocumentMessageHandler&MockObject $handler;
    private ComplianceDocumentRepositoryInterface&MockObject $documentRepository;
    private MessageBusInterface&MockObject $messageBus;
    private DocumentReceivedListener $listener;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ProcessAndStoreKycDocumentMessageHandler::class);
        $this->documentRepository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->listener = new DocumentReceivedListener($this->handler, $this->documentRepository, $this->messageBus, new NullLogger());
    }

    private function event(?string $previousPath = null, DocumentType $type = DocumentType::KBIS): DocumentReceivedLocalEvent
    {
        $folder = $this->createEntityState(BusinessFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-018',
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
        $folder->requireDocument($type, isMandatory: true);

        $document = $folder->documents->first();
        new \ReflectionProperty(ComplianceDocument::class, 'id')->setValue($document, Uuid::v7());
        if (null !== $previousPath) {
            $document->markAsUploaded($previousPath, 'old.pdf', 'application/pdf', 10);
        }
        $document->markAsProcessed();

        return new DocumentReceivedLocalEvent($document, $folder, '/tmp/x.pdf', 'application/pdf', 'kbis.pdf', 1024, $previousPath);
    }

    public function testStoresTheFileSynchronouslyWithoutGoingThroughTheQueue(): void
    {
        $event = $this->event();
        $this->messageBus->expects($this->once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));
        $this->handler->expects($this->once())
            ->method('__invoke')
            ->with($this->callback(static fn (ProcessAndStoreKycDocumentMessage $message): bool => '/tmp/x.pdf' === $message->localTempPath
                && $message->documentId === $event->complianceDocument->id?->toString()))
            ->willReturnCallback(static function () use ($event): void {
                $event->complianceDocument->markAsUploaded('compliance_folders/comp_fol_1/kbis.pdf', 'kbis.pdf', 'application/pdf', 1024);
            });
        $this->documentRepository->expects($this->never())->method('save');

        ($this->listener)($event);

        self::assertSame(DocumentStatus::UPLOADED, $event->complianceDocument->status);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAStorageFailureReleasesTheDocumentSoTheClientCanRetry(): void
    {
        $event = $this->event();
        $this->handler->method('__invoke')->willThrowException(new \RuntimeException('S3 indisponible'));
        $this->documentRepository->expects($this->once())->method('save')->with($event->complianceDocument);

        try {
            ($this->listener)($event);
            self::fail('L\'échec doit remonter au client.');
        } catch (\RuntimeException) {
            // attendu
        }

        self::assertSame(DocumentStatus::PENDING, $event->complianceDocument->status);
        self::assertTrue($event->complianceDocument->canBeReplacedByClient());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAFailedReplacementKeepsTheExistingFile(): void
    {
        $event = $this->event('compliance_folders/comp_fol_1/old.pdf');
        $this->handler->method('__invoke')->willThrowException(new \RuntimeException('S3 indisponible'));
        $this->documentRepository->expects($this->once())->method('save');

        try {
            ($this->listener)($event);
        } catch (\RuntimeException) {
        }

        self::assertSame(DocumentStatus::UPLOADED, $event->complianceDocument->status);
        self::assertSame('compliance_folders/comp_fol_1/old.pdf', $event->complianceDocument->storagePath);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAMissingTemporaryFileDoesNotLeaveTheDocumentStuckInProcessing(): void
    {
        $event = $this->event();
        // Le handler « réussit » sans rien faire (fichier temporaire introuvable) : la pièce reste en traitement.
        $this->handler->method('__invoke');
        $this->documentRepository->expects($this->once())->method('save');

        try {
            ($this->listener)($event);
            self::fail('Une pièce non stockée doit être signalée.');
        } catch (\RuntimeException) {
        }

        self::assertSame(DocumentStatus::PENDING, $event->complianceDocument->status);
    }

    private function storeSucceeds(DocumentReceivedLocalEvent $event): void
    {
        $this->handler->method('__invoke')->willReturnCallback(static function () use ($event): void {
            $event->complianceDocument->markAsUploaded('compliance_folders/comp_fol_1/doc.pdf', 'doc.pdf', 'application/pdf', 1024);
        });
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRequestsTheOcrAnalysisOnceTheFileIsStored(): void
    {
        $event = $this->event();
        $this->storeSucceeds($event);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (ProcessOcrMessage $message): bool => $message->documentId === $event->complianceDocument->id?->toString()))
            ->willReturn(new Envelope(new \stdClass()));

        ($this->listener)($event);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotRequestAnalysisForATypeWithoutOcr(): void
    {
        $event = $this->event(type: DocumentType::RBE);
        $this->storeSucceeds($event);

        $this->messageBus->expects($this->never())->method('dispatch');

        ($this->listener)($event);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotRequestAnalysisWhenStorageFails(): void
    {
        $event = $this->event();
        $this->handler->method('__invoke')->willThrowException(new \RuntimeException('S3 indisponible'));

        $this->messageBus->expects($this->never())->method('dispatch');

        try {
            ($this->listener)($event);
        } catch (\RuntimeException) {
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnUnreachableQueueNeverFailsTheClientUpload(): void
    {
        $event = $this->event();
        $this->storeSucceeds($event);
        $this->messageBus->method('dispatch')->willThrowException(new TransportException('broker injoignable'));

        ($this->listener)($event);

        self::assertSame(DocumentStatus::UPLOADED, $event->complianceDocument->status);
    }
}
