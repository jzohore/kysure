<?php

declare(strict_types=1);

namespace App\Tests\Application\Support\UseCase;

use App\Application\Support\UseCase\ReplyToSupportThreadUseCase;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Shared\Port\RealTimeNotifierInterface;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Event\SupportThreadRepliedByAdminEvent;
use App\Domain\Support\Exception\InvalidSupportAttachmentException;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ReplyToSupportThreadUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private SupportThreadRepositoryInterface&MockObject $threadRepository;
    private RealTimeNotifierInterface&MockObject $notifier;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private DocumentStorageInterface&MockObject $documentStorage;
    private ReplyToSupportThreadUseCase $useCase;

    protected function setUp(): void
    {
        $this->threadRepository = $this->createMock(SupportThreadRepositoryInterface::class);
        $this->notifier = $this->createMock(RealTimeNotifierInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->documentStorage = $this->createMock(DocumentStorageInterface::class);

        $this->useCase = new ReplyToSupportThreadUseCase(
            $this->threadRepository,
            $this->notifier,
            $this->eventDispatcher,
            $this->documentStorage,
        );
    }

    private function openThread(): SupportThread
    {
        return SupportThread::open(
            $this->createStub(Workspace::class),
            $this->createEntityState(User::class, ['slugId' => 'usr_test']),
            'category',
            'topic',
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAdminReplyIsSavedAndDispatchesRepliedEvent(): void
    {
        $thread = $this->openThread();

        $this->threadRepository->expects($this->once())->method('save')->with($thread);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::isInstanceOf(SupportThreadRepliedByAdminEvent::class));

        $this->useCase->execute($thread, 'Bonjour, comment vous aider ?', SupportSenderType::ADMIN);

        self::assertCount(1, $thread->messages);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClientReplyIsSavedButDoesNotDispatchRepliedEvent(): void
    {
        $thread = $this->openThread();

        $this->threadRepository->expects($this->once())->method('save')->with($thread);
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->useCase->execute($thread, 'Toujours un souci de mon côté.', SupportSenderType::CLIENT);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testValidAttachmentIsStoredAndAttachedToTheMessage(): void
    {
        $thread = $this->openThread();

        $file = $this->createMock(UploadedFile::class);
        $file->method('getSize')->willReturn(1024);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getClientOriginalName')->willReturn('facture.pdf');

        $this->documentStorage->expects($this->once())
            ->method('store')
            ->with($file, 'support_threads/' . $thread->slugId)
            ->willReturn('support_threads/' . $thread->slugId . '/facture-123.pdf');

        $this->threadRepository->expects($this->once())->method('save')->with($thread);

        $this->useCase->execute($thread, '', SupportSenderType::ADMIN, $file);

        /** @var \App\Domain\Support\Entity\SupportMessage $message */
        $message = $thread->messages->first();
        self::assertTrue($message->hasAttachment());
        self::assertSame('facture.pdf', $message->attachmentFilename);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOversizedAttachmentIsRejectedBeforeAnyPersistence(): void
    {
        $thread = $this->openThread();

        $file = $this->createMock(UploadedFile::class);
        $file->method('getSize')->willReturn(11 * 1024 * 1024);
        $file->method('getMimeType')->willReturn('application/pdf');

        $this->documentStorage->expects($this->never())->method('store');
        $this->threadRepository->expects($this->never())->method('save');

        $this->expectException(InvalidSupportAttachmentException::class);

        $this->useCase->execute($thread, '', SupportSenderType::ADMIN, $file);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnsupportedMimeTypeIsRejected(): void
    {
        $thread = $this->openThread();

        $file = $this->createMock(UploadedFile::class);
        $file->method('getSize')->willReturn(1024);
        $file->method('getMimeType')->willReturn('application/zip');

        $this->documentStorage->expects($this->never())->method('store');
        $this->threadRepository->expects($this->never())->method('save');

        $this->expectException(InvalidSupportAttachmentException::class);

        $this->useCase->execute($thread, '', SupportSenderType::ADMIN, $file);
    }
}
