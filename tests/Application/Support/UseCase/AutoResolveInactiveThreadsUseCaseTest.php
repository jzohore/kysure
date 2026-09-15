<?php

declare(strict_types=1);

namespace App\Tests\Application\Support\UseCase;

use App\Application\Support\UseCase\AutoResolveInactiveThreadsUseCase;
use App\Domain\Shared\Port\RealTimeNotifierInterface;
use App\Domain\Support\Entity\SupportMessage;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportSenderType;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille le comportement de clôture automatique des tickets inactifs — le bug corrigé
 * en P0 (seuil d'inactivité mal appliqué) doit désormais être détecté par ces tests.
 */
final class AutoResolveInactiveThreadsUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private SupportThreadRepositoryInterface&MockObject $threadRepository;
    private RealTimeNotifierInterface&MockObject $notifier;
    private AutoResolveInactiveThreadsUseCase $useCase;

    protected function setUp(): void
    {
        $this->threadRepository = $this->createMock(SupportThreadRepositoryInterface::class);
        $this->notifier = $this->createMock(RealTimeNotifierInterface::class);
        $this->useCase = new AutoResolveInactiveThreadsUseCase($this->threadRepository, $this->notifier);
    }

    private function openThread(): SupportThread
    {
        return SupportThread::open(
            $this->createStub(Workspace::class),
            $this->createStub(User::class),
            'category',
            'topic',
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThreadsPendingClosureAreResolvedAndWarningIsReset(): void
    {
        $thread = $this->createEntityState(SupportThread::class, [
            'slugId' => 'sup_thr_test',
            'status' => SupportThreadStatus::OPEN,
            'closureWarningSent' => true,
        ]);

        $this->threadRepository->method('findThreadsPendingClosure')->willReturn([$thread]);
        $this->threadRepository->method('findInactiveThreadsForWarning')->willReturn([]);
        $this->threadRepository->expects($this->once())->method('save')->with($thread);

        $result = $this->useCase->execute(new \DateInterval('PT2H'), new \DateInterval('PT30M'));

        self::assertSame(SupportThreadStatus::RESOLVED, $thread->status);
        self::assertFalse($thread->closureWarningSent);
        self::assertSame(['warned' => 0, 'resolved' => 1], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInactiveThreadIsWarnedOnlyWhenLastMessageIsFromAdmin(): void
    {
        $thread = $this->openThread();
        SupportMessage::write($thread, SupportSenderType::ADMIN, 'On attend votre retour.');

        $this->threadRepository->method('findThreadsPendingClosure')->willReturn([]);
        $this->threadRepository->method('findInactiveThreadsForWarning')->willReturn([$thread]);
        $this->threadRepository->expects($this->once())->method('save')->with($thread);

        $result = $this->useCase->execute(new \DateInterval('PT2H'), new \DateInterval('PT30M'));

        self::assertCount(2, $thread->messages);
        self::assertTrue($thread->closureWarningSent);
        self::assertSame(['warned' => 1, 'resolved' => 0], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInactiveThreadIsNotWarnedWhenLastMessageIsFromClient(): void
    {
        $thread = $this->openThread();
        SupportMessage::write($thread, SupportSenderType::CLIENT, 'Toujours en attente de votre part.');

        $this->threadRepository->method('findThreadsPendingClosure')->willReturn([]);
        $this->threadRepository->method('findInactiveThreadsForWarning')->willReturn([$thread]);
        $this->threadRepository->expects($this->never())->method('save');

        $result = $this->useCase->execute(new \DateInterval('PT2H'), new \DateInterval('PT30M'));

        self::assertCount(1, $thread->messages);
        self::assertFalse($thread->closureWarningSent);
        self::assertSame(['warned' => 0, 'resolved' => 0], $result);
    }
}
