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
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class DocumentReceivedListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private ProcessAndStoreKycDocumentMessageHandler&MockObject $handler;
    private ComplianceDocumentRepositoryInterface&MockObject $documentRepository;
    private DocumentReceivedListener $listener;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ProcessAndStoreKycDocumentMessageHandler::class);
        $this->documentRepository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->listener = new DocumentReceivedListener($this->handler, $this->documentRepository, new NullLogger());
    }

    private function event(?string $previousPath = null): DocumentReceivedLocalEvent
    {
        $folder = $this->createEntityState(BusinessFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-018',
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);

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
}
