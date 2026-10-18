<?php

declare(strict_types=1);

namespace App\Tests\Application\Firm;

use App\Application\Firm\DTO\Request\UpdateRegulatoryProfileRequest;
use App\Application\Firm\UseCase\CompleteRegulatoryProfileOnboardingUseCase;
use App\Application\Firm\UseCase\UpdateRegulatoryProfileUseCase;
use App\Domain\User\Entity\User;
use App\Domain\User\Enum\OnboardingStatus;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\Workspace\Exception\NotWorkspaceAdminException;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CompleteRegulatoryProfileOnboardingUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private UpdateRegulatoryProfileUseCase&MockObject $updateRegulatoryProfile;
    private UserRepositoryInterface&MockObject $userRepository;
    private CompleteRegulatoryProfileOnboardingUseCase $useCase;

    protected function setUp(): void
    {
        $this->updateRegulatoryProfile = $this->createMock(UpdateRegulatoryProfileUseCase::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);

        $this->useCase = new CompleteRegulatoryProfileOnboardingUseCase(
            $this->updateRegulatoryProfile,
            $this->userRepository,
        );
    }

    private function user(OnboardingStatus $status): User
    {
        return $this->createEntityState(User::class, ['onboardingStatus' => $status]);
    }

    public function testSavesTheProfileThenAdvancesTheOnboarding(): void
    {
        $user = $this->user(OnboardingStatus::PLAN_SETUP);
        $request = new UpdateRegulatoryProfileRequest();

        $this->updateRegulatoryProfile->expects($this->once())->method('__invoke')->with($request);
        $this->userRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (User $u): bool => OnboardingStatus::REGULATORY_PROFILE_SETUP === $u->onboardingStatus));

        ($this->useCase)($request, $user);
    }

    public function testReplayStillUpdatesTheProfileButDoesNotResaveTheUser(): void
    {
        $user = $this->user(OnboardingStatus::REGULATORY_PROFILE_SETUP);
        $request = new UpdateRegulatoryProfileRequest();

        $this->updateRegulatoryProfile->expects($this->once())->method('__invoke')->with($request);
        $this->userRepository->expects($this->never())->method('save');

        ($this->useCase)($request, $user);
    }

    public function testDoesNotAdvanceWhenTheProfileUpdateIsRefused(): void
    {
        $user = $this->user(OnboardingStatus::PLAN_SETUP);

        $this->updateRegulatoryProfile->expects($this->once())->method('__invoke')->willThrowException(NotWorkspaceAdminException::create());
        $this->userRepository->expects($this->never())->method('save');

        $this->expectException(NotWorkspaceAdminException::class);
        try {
            ($this->useCase)(new UpdateRegulatoryProfileRequest(), $user);
        } finally {
            self::assertSame(OnboardingStatus::PLAN_SETUP, $user->onboardingStatus);
        }
    }
}
