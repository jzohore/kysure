<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Workspace\Handler;

use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\Compliance\Message\FinalizeMeetingAudioMessage;
use App\Infrastructure\Workspace\Handler\FinalizeMeetingAudioHandler;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;

final class FinalizeMeetingAudioHandlerTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string SLUG = 'comp_fol_abc123';
    private const string SESSION_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    public function testReplayedMessageDoesNothingButCleansLeftoverChunks(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['slugId' => self::SLUG]);

        $folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepository->method('findOneBySlugId')->willReturn($folder);

        $recordRepository = $this->createMock(MeetingRecordRepositoryInterface::class);
        $recordRepository->expects($this->once())->method('existsForSession')->willReturn(true);

        $leftovers = ['tmp/meetings/x/y/chunks/000000.chunk', 'tmp/meetings/x/y/chunks/000001.chunk'];
        $storage = $this->createMock(DocumentStorageInterface::class);
        $storage->method('listFiles')->willReturn($leftovers);
        $storage->expects($this->never())->method('store');
        $storage->expects($this->exactly(2))->method('delete');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $this->handler($folderRepository, $recordRepository, $storage, $eventDispatcher)(
            new FinalizeMeetingAudioMessage(self::SLUG, self::SESSION_ID, 120)
        );
    }

    public function testMissingChunksOnFirstRunFailsSoMessengerRetries(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['slugId' => self::SLUG]);

        $folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepository->method('findOneBySlugId')->willReturn($folder);

        $recordRepository = $this->createStub(MeetingRecordRepositoryInterface::class);
        $recordRepository->method('existsForSession')->willReturn(false);

        $storage = $this->createStub(DocumentStorageInterface::class);
        $storage->method('listFiles')->willReturn([]);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\RuntimeException::class);

        $this->handler($folderRepository, $recordRepository, $storage, $eventDispatcher)(
            new FinalizeMeetingAudioMessage(self::SLUG, self::SESSION_ID, 120)
        );
    }

    private function handler(
        ComplianceFolderRepositoryInterface $folderRepository,
        MeetingRecordRepositoryInterface $recordRepository,
        DocumentStorageInterface $storage,
        EventDispatcherInterface $eventDispatcher,
    ): FinalizeMeetingAudioHandler {
        return new FinalizeMeetingAudioHandler(
            $folderRepository,
            $recordRepository,
            $storage,
            $this->createStub(LoggerInterface::class),
            $this->createStub(Filesystem::class),
            $eventDispatcher,
        );
    }
}
