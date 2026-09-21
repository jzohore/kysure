<?php

declare(strict_types=1);

namespace App\Tests\Application\Support\UseCase;

use App\Application\Support\UseCase\AlertOverdueSupportThreadsUseCase;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Port\SupportSlaBreachNotifierInterface;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AlertOverdueSupportThreadsUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private SupportThreadRepositoryInterface&MockObject $threadRepository;
    private SupportSlaBreachNotifierInterface&MockObject $notifier;
    private AlertOverdueSupportThreadsUseCase $useCase;

    protected function setUp(): void
    {
        $this->threadRepository = $this->createMock(SupportThreadRepositoryInterface::class);
        $this->notifier = $this->createMock(SupportSlaBreachNotifierInterface::class);
        $this->useCase = new AlertOverdueSupportThreadsUseCase($this->threadRepository, $this->notifier, new NullLogger());
    }

    private function overdueThread(): SupportThread
    {
        return $this->createEntityState(SupportThread::class, [
            'slugId' => 'sup_thr_test',
            'workspace' => $this->createStub(Workspace::class),
            'user' => $this->createStub(User::class),
            'status' => SupportThreadStatus::OPEN,
            'dueAt' => new \DateTimeImmutable('-1 hour'),
            'slaBreachAlertSent' => false,
        ]);
    }

    public function testAlertsAndMarksEachOverdueThreadAsSent(): void
    {
        $first = $this->overdueThread();
        $second = $this->overdueThread();

        $this->threadRepository->method('findOverdueOpenThreadsNeedingAlert')->willReturn([$first, $second]);

        $this->notifier->expects($this->exactly(2))->method('alert')
            ->with($this->logicalOr($first, $second));

        $this->threadRepository->expects($this->exactly(2))->method('save')
            ->with($this->logicalOr($first, $second));

        $count = $this->useCase->execute();

        self::assertSame(2, $count);
        self::assertTrue($first->slaBreachAlertSent);
        self::assertTrue($second->slaBreachAlertSent);
    }

    public function testDoesNothingWhenNoThreadIsOverdue(): void
    {
        $this->threadRepository->method('findOverdueOpenThreadsNeedingAlert')->willReturn([]);

        $this->notifier->expects($this->never())->method('alert');
        $this->threadRepository->expects($this->never())->method('save');

        self::assertSame(0, $this->useCase->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAFailedSlackAlertDoesNotBlockTheOthersAndLeavesTheFlagUnset(): void
    {
        $failing = $this->overdueThread();
        $succeeding = $this->overdueThread();

        $this->threadRepository->method('findOverdueOpenThreadsNeedingAlert')->willReturn([$failing, $succeeding]);

        $this->notifier->method('alert')->willReturnCallback(static function (SupportThread $thread) use ($failing): void {
            if ($thread === $failing) {
                throw new \RuntimeException('canal Slack introuvable');
            }
        });

        $this->threadRepository->expects($this->once())->method('save')->with($succeeding);

        $count = $this->useCase->execute();

        self::assertSame(1, $count);
        self::assertFalse($failing->slaBreachAlertSent);
        self::assertTrue($succeeding->slaBreachAlertSent);
    }
}
