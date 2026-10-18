<?php

declare(strict_types=1);

namespace App\Tests\Application\Firm;

use App\Application\Firm\UseCase\DeferRegulatoryProfileOnboardingUseCase;
use App\Domain\Firm\Event\RegulatoryProfileDeferredEvent;
use App\Domain\User\Entity\User;
use App\Domain\User\Enum\OnboardingStatus;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class DeferRegulatoryProfileOnboardingUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private UserRepositoryInterface&MockObject $userRepository;
    private WorkspaceMemberRepositoryInterface&MockObject $workspaceMemberRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private DeferRegulatoryProfileOnboardingUseCase $useCase;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->workspaceMemberRepository = $this->createMock(WorkspaceMemberRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->useCase = new DeferRegulatoryProfileOnboardingUseCase(
            $this->userRepository,
            $this->workspaceMemberRepository,
            $this->eventDispatcher,
        );
    }

    private function user(OnboardingStatus $status): User
    {
        return $this->createEntityState(User::class, [
            'id' => Uuid::v7(),
            'onboardingStatus' => $status,
        ]);
    }

    public function testDefersTheStepSavesTheUserAndDispatchesTheAuditedEvent(): void
    {
        $user = $this->user(OnboardingStatus::PLAN_SETUP);
        $workspace = $this->createEntityState(Workspace::class, []);
        $member = $this->createEntityState(WorkspaceMember::class, ['workspace' => $workspace]);

        $this->workspaceMemberRepository->expects($this->once())->method('findOneByUser')->willReturn($member);

        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (User $u): bool => OnboardingStatus::REGULATORY_PROFILE_SETUP === $u->onboardingStatus));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (RegulatoryProfileDeferredEvent $e): bool => $e->user === $user && $e->workspace === $workspace))
            ->willReturnArgument(0);

        ($this->useCase)($user);
    }

    public function testIsIdempotentWhenTheStepWasAlreadyPassed(): void
    {
        $user = $this->user(OnboardingStatus::REGULATORY_PROFILE_SETUP);
        $member = $this->createEntityState(WorkspaceMember::class, [
            'workspace' => $this->createEntityState(Workspace::class, []),
        ]);

        $this->workspaceMemberRepository->expects($this->once())->method('findOneByUser')->willReturn($member);
        $this->userRepository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->useCase)($user);
    }

    public function testRefusesToSkipBeforeThePlanIsChosen(): void
    {
        $user = $this->user(OnboardingStatus::WORKSPACE_SETUP);
        $member = $this->createEntityState(WorkspaceMember::class, [
            'workspace' => $this->createEntityState(Workspace::class, []),
        ]);

        $this->workspaceMemberRepository->expects($this->once())->method('findOneByUser')->willReturn($member);
        $this->userRepository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);
        ($this->useCase)($user);
    }

    public function testThrowsWhenNoWorkspaceIsAttached(): void
    {
        $user = $this->user(OnboardingStatus::PLAN_SETUP);

        $this->workspaceMemberRepository->expects($this->once())->method('findOneByUser')->willReturn(null);
        $this->userRepository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\InvalidArgumentException::class);
        ($this->useCase)($user);
    }
}
