<?php

declare(strict_types=1);

namespace App\Tests\Application\ComplianceFolder;

use App\Application\Compliance\UseCase\ComplianceFolder\DiscardFlashDraftUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\FolderCreationMethod;
use App\Domain\Compliance\Event\FlashDraftDiscardedEvent;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class DiscardFlashDraftUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string FOLDER_SLUG = 'comp_fol_flash_1';

    private ComplianceFolderRepositoryInterface&MockObject $repository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private DiscardFlashDraftUseCase $useCase;
    private Workspace $workspace;
    private string $folderId;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->useCase = new DiscardFlashDraftUseCase($this->repository, $this->eventDispatcher);

        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_cabinet_a', 'name' => 'Cabinet A']);
        $this->folderId = Uuid::v7()->toRfc4122();
    }

    public function testDiscardsAFlashDraftAndDispatchesTheSystemEvent(): void
    {
        $folder = $this->flashFolder();
        $this->repository->method('findById')->willReturn($folder);

        $this->repository->expects($this->once())->method('save')->with($this->identicalTo($folder));
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (mixed $event): bool => $event instanceof FlashDraftDiscardedEvent
                && self::FOLDER_SLUG === $event->folderSlugId
                && DiscardFlashDraftUseCase::SYSTEM_ACTOR === $event->actorName))
            ->willReturnArgument(0);

        self::assertTrue(($this->useCase)($this->folderId, $this->workspace));
        self::assertSame(ComplianceFolderStatus::DELETED, $folder->status);

        $lastEntry = $folder->history[array_key_last($folder->history)];
        self::assertStringContainsString(DiscardFlashDraftUseCase::SYSTEM_ACTOR, $lastEntry['description']);
    }

    public function testIsANoOpForAMalformedSessionId(): void
    {
        $this->repository->expects($this->never())->method('findById');
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        self::assertFalse(($this->useCase)('pas-un-uuid', $this->workspace));
    }

    public function testIsANoOpWhenTheFolderNoLongerExists(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->willThrowException(ComplianceFolderNotFoundException::withId($this->folderId));
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        self::assertFalse(($this->useCase)($this->folderId, $this->workspace));
    }

    public function testIsANoOpWhenReplayedOnAnAlreadyDiscardedDraft(): void
    {
        $this->repository->method('findById')->willReturn($this->flashFolder(['status' => ComplianceFolderStatus::DELETED]));

        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        self::assertFalse(($this->useCase)($this->folderId, $this->workspace));
    }

    public function testRefusesAFolderOfAnotherWorkspace(): void
    {
        $otherWorkspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_cabinet_b', 'name' => 'Cabinet B']);
        $this->repository->method('findById')->willReturn($this->flashFolder(['workspace' => $otherWorkspace]));

        $this->expectNothingPersisted();
        $this->expectException(\DomainException::class);

        ($this->useCase)($this->folderId, $this->workspace);
    }

    public function testRefusesAFolderOpenedByTheCabinet(): void
    {
        $this->repository->method('findById')->willReturn($this->flashFolder(['creationMethod' => FolderCreationMethod::CABINET->value]));

        $this->expectNothingPersisted();
        $this->expectException(\DomainException::class);

        ($this->useCase)($this->folderId, $this->workspace);
    }

    public function testRefusesAFolderThatIsNoLongerADraft(): void
    {
        $this->repository->method('findById')->willReturn($this->flashFolder(['status' => ComplianceFolderStatus::DER_SENT]));

        $this->expectNothingPersisted();
        $this->expectException(\DomainException::class);

        ($this->useCase)($this->folderId, $this->workspace);
    }

    public function testRefusesADraftThatAlreadyCarriesEvidence(): void
    {
        $this->repository->method('findById')->willReturn($this->flashFolder(['submittedAt' => new \DateTimeImmutable()]));

        $this->expectNothingPersisted();
        $this->expectException(\DomainException::class);

        ($this->useCase)($this->folderId, $this->workspace);
    }

    private function expectNothingPersisted(): void
    {
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function flashFolder(array $overrides = []): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'slugId' => self::FOLDER_SLUG,
            'reference' => 'KYC-FLASH01',
            'workspace' => $this->workspace,
            'creationMethod' => FolderCreationMethod::FLASH_QR_CODE->value,
            'status' => ComplianceFolderStatus::DRAFT,
            'documents' => new ArrayCollection(),
            'meetingRecordings' => new ArrayCollection(),
            ...$overrides,
        ]);
    }
}
