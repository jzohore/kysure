<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr\Listener;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrRerunRequestedEvent;
use App\Infrastructure\Ocr\Listener\DispatchOcrOnRerunRequestedListener;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class DispatchOcrOnRerunRequestedListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string DOCUMENT_ID = '0192f0a0-0000-7000-8000-000000000001';

    private function event(?Uuid $id): DocumentOcrRerunRequestedEvent
    {
        $folder = $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_1', 'reference' => 'DOS-1', 'history' => []]);

        return new DocumentOcrRerunRequestedEvent($this->createEntityState(ComplianceDocument::class, [
            'id' => $id,
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'acknowledgements' => new ArrayCollection(),
        ]));
    }

    public function testQueuesTheAnalysisByDocumentUuid(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->with($this->callback(
            static fn (object $message): bool => $message instanceof ProcessOcrMessage && self::DOCUMENT_ID === $message->documentId,
        ))->willReturn(new Envelope(new ProcessOcrMessage(self::DOCUMENT_ID)));

        new DispatchOcrOnRerunRequestedListener($bus)($this->event(Uuid::fromString(self::DOCUMENT_ID)));
    }

    public function testAnUnreachableQueueIsReportedToTheOperator(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('AMQP indisponible'));

        $this->expectException(TransportException::class);

        new DispatchOcrOnRerunRequestedListener($bus)($this->event(Uuid::fromString(self::DOCUMENT_ID)));
    }

    public function testAnUnsavedDocumentIsNeverQueued(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(\InvalidArgumentException::class);

        new DispatchOcrOnRerunRequestedListener($bus)($this->event(null));
    }
}
