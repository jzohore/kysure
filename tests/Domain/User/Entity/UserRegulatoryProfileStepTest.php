<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\Entity;

use App\Domain\User\Entity\User;
use App\Domain\User\Enum\OnboardingStatus;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserRegulatoryProfileStepTest extends TestCase
{
    use ReflectionHelperTrait;

    private function user(OnboardingStatus $status): User
    {
        return $this->createEntityState(User::class, ['onboardingStatus' => $status]);
    }

    public function testStepAdvancesFromPlanSetup(): void
    {
        $user = $this->user(OnboardingStatus::PLAN_SETUP);

        self::assertTrue($user->completeRegulatoryProfileStep());
        self::assertSame(OnboardingStatus::REGULATORY_PROFILE_SETUP, $user->onboardingStatus);
    }

    #[DataProvider('alreadyPassedStatuses')]
    public function testStepIsIdempotentOnceAlreadyPassed(OnboardingStatus $status): void
    {
        $user = $this->user($status);

        self::assertFalse($user->completeRegulatoryProfileStep());
        self::assertSame($status, $user->onboardingStatus);
    }

    #[DataProvider('tooEarlyStatuses')]
    public function testStepCannotBeSkippedAheadOfThePlanChoice(OnboardingStatus $status): void
    {
        $user = $this->user($status);

        $this->expectException(\DomainException::class);
        $user->completeRegulatoryProfileStep();
    }

    /**
     * @return iterable<string, array{OnboardingStatus}>
     */
    public static function alreadyPassedStatuses(): iterable
    {
        yield 'étape déjà traitée' => [OnboardingStatus::REGULATORY_PROFILE_SETUP];
        yield 'onboarding terminé' => [OnboardingStatus::COMPLETED];
    }

    /**
     * @return iterable<string, array{OnboardingStatus}>
     */
    public static function tooEarlyStatuses(): iterable
    {
        yield 'compte tout juste créé' => [OnboardingStatus::PENDING];
        yield 'cabinet créé, plan non choisi' => [OnboardingStatus::WORKSPACE_SETUP];
    }
}
