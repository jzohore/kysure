<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\UseCase\ComplianceFolder\RejectComplianceFolderUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Event\ComplianceFolderRejectedEvent;
use App\Domain\Compliance\Exception\FolderStateException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class RejectComplianceFolderUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private User $reviewer;
    private ComplianceFolderRepositoryInterface&MockObject $folderRepository;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $this->reviewer = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $this->folderRepository = $this->createMock(ComplianceFolderRepositoryInterface::class);
    }

    private function folder(ComplianceFolderStatus $status): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'workspace' => $this->workspace,
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-001',
            'status' => $status,
            'history' => [],
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
        ]);
    }

    private function buildUseCase(?EventDispatcherInterface $dispatcher = null): RejectComplianceFolderUseCase
    {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->reviewer);

        return new RejectComplianceFolderUseCase(
            $this->folderRepository,
            $transactionManager,
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesToRejectAFolderNotInReview(): void
    {
        $useCase = $this->buildUseCase();

        $this->expectException(FolderStateException::class);
        ($useCase)($this->folder(ComplianceFolderStatus::DRAFT), 'Motif.');
    }

    public function testRejectsAndDispatchesTheEvent(): void
    {
        $folder = $this->folder(ComplianceFolderStatus::IN_REVIEW);
        $this->folderRepository->expects($this->once())->method('save')->with($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with(
            $this->callback(static function (ComplianceFolderRejectedEvent $event) use ($folder): bool {
                self::assertSame($folder->slugId, $event->folderSlugId);
                self::assertSame('Pièces incohérentes.', $event->reason);
                self::assertSame('Marie CURIE', $event->rejectedByName);

                return true;
            }),
        );

        $useCase = $this->buildUseCase($dispatcher);
        ($useCase)($folder, 'Pièces incohérentes.');

        self::assertSame(ComplianceFolderStatus::REJECTED, $folder->status);
    }
}
