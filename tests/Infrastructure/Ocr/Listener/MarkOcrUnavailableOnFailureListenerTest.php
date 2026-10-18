<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr\Listener;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Infrastructure\Ocr\Listener\MarkOcrUnavailableOnFailureListener;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class MarkOcrUnavailableOnFailureListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private ComplianceDocumentRepositoryInterface&MockObject $repository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private MarkOcrUnavailableOnFailureListener $listener;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->listener = new MarkOcrUnavailableOnFailureListener($this->repository, $this->eventDispatcher, new NullLogger());
    }

    private function document(): ComplianceDocument
    {
        $folder = $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'history' => [],
        ]);

        return $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'compliance_folders/comp_fol_1/doc.pdf',
            'acknowledgements' => new ArrayCollection(),
        ]);
    }

    private function failure(object $message, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async_heavy', new \RuntimeException('Throttling AWS'));
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    public function testSignalsThePieceOnceRetriesAreExhausted(): void
    {
        $document = $this->document();
        $this->repository->method('findById')->willReturn($document);
        $this->repository->expects($this->once())->method('save')->with($document);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DocumentOcrUnavailableEvent $e): bool => $e->document === $document))
            ->willReturnArgument(0);

        ($this->listener)($this->failure(new ProcessOcrMessage($document->id?->toString() ?? ''), willRetry: false));

        self::assertNotNull($document->ocrUnavailableAt);
        self::assertNotEmpty($document->ocrFindings);
        // Jamais de rejet : la pièce reste à vérifier par le CGP.
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
    }

    public function testDoesNothingWhileAnotherAttemptIsScheduled(): void
    {
        $this->repository->expects($this->never())->method('findById');
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->listener)($this->failure(new ProcessOcrMessage('x'), willRetry: true));
    }

    public function testIgnoresOtherMessages(): void
    {
        $this->repository->expects($this->never())->method('findById');
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->listener)($this->failure(new \stdClass(), willRetry: false));
    }

    public function testAReplayedFailureIsNotSignalledNorAuditedTwice(): void
    {
        $document = $this->document();
        $document->markOcrUnavailable();
        $this->repository->method('findById')->willReturn($document);
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->listener)($this->failure(new ProcessOcrMessage('x'), willRetry: false));
    }

    public function testNeverOverwritesAnAnalysisThatSucceededMeanwhile(): void
    {
        $document = $this->document();
        $document->attachOcrAnalysis(['holder_name' => 'Jean'], [], 'v1');
        $this->repository->method('findById')->willReturn($document);
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->listener)($this->failure(new ProcessOcrMessage('x'), willRetry: false));

        self::assertSame(['holder_name' => 'Jean'], $document->ocrData);
    }

    public function testAnUnknownDocumentIsIgnoredWithoutFailingTheWorker(): void
    {
        $this->repository->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->listener)($this->failure(new ProcessOcrMessage('x'), willRetry: false));
    }
}
