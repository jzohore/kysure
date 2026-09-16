<?php

declare(strict_types=1);

namespace App\Tests\Application\Dashboard;

use App\Application\Dashboard\DTO\UserDashboardStats;
use PHPUnit\Framework\TestCase;

final class UserDashboardStatsTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function stats(array $overrides = []): UserDashboardStats
    {
        $defaults = [
            'workspaceName' => 'Cabinet Test',
            'isFirm' => false,
            'hasActiveSubscription' => false,
            'canOpenFolder' => true,
            'trialDossiersRemaining' => 5,
            'remainingMeetingMinutes' => 90,
            'meetingMinutesAllocated' => 90,
            'activeFoldersCount' => 0,
            'draftFoldersCount' => 0,
            'totalFoldersCount' => 0,
            'clientsCount' => 0,
            'teamMembersCount' => 1,
            'pendingScreeningsCount' => 0,
            'pendingDocsCount' => 0,
            'inReviewCount' => 0,
            'needsCorrectionCount' => 0,
            'approvedCount' => 0,
            'latestFolders' => [],
            'latestScreenings' => [],
            'isOrgCompleted' => false,
            'isRegProfileValid' => false,
            'is2faEnabled' => false,
        ];

        return new UserDashboardStats(...array_merge($defaults, $overrides));
    }

    public function testSoloWorkspaceHasFiveOnboardingSteps(): void
    {
        self::assertSame(5, $this->stats(['isFirm' => false])->onboardingTotalSteps());
    }

    public function testFirmWorkspaceHasSixOnboardingSteps(): void
    {
        self::assertSame(6, $this->stats(['isFirm' => true])->onboardingTotalSteps());
    }

    public function testFreshWorkspaceHasOnlyTheAccountStepDone(): void
    {
        $stats = $this->stats();

        self::assertSame(1, $stats->onboardingDoneSteps());
        self::assertFalse($stats->isOnboardingComplete());
    }

    public function testEachCompletedItemCountsOnce(): void
    {
        $stats = $this->stats([
            'isOrgCompleted' => true,
            'isRegProfileValid' => true,
            'is2faEnabled' => true,
            'totalFoldersCount' => 2,
        ]);

        self::assertSame(5, $stats->onboardingDoneSteps());
        self::assertTrue($stats->isOnboardingComplete());
    }

    public function testFirmIsNotCompleteUntilTeamStepIsDone(): void
    {
        $base = [
            'isFirm' => true,
            'isOrgCompleted' => true,
            'isRegProfileValid' => true,
            'is2faEnabled' => true,
            'totalFoldersCount' => 1,
        ];

        self::assertFalse($this->stats($base)->isOnboardingComplete());
        self::assertTrue($this->stats($base + ['hasActiveSubscription' => true])->isOnboardingComplete());
        self::assertTrue($this->stats(array_merge($base, ['teamMembersCount' => 2]))->isOnboardingComplete());
    }

    public function testHasFirstFolderReflectsTotalCount(): void
    {
        self::assertFalse($this->stats(['totalFoldersCount' => 0])->hasFirstFolder());
        self::assertTrue($this->stats(['totalFoldersCount' => 1])->hasFirstFolder());
    }

    public function testTrialLowOnlyWhenNotSubscribedAndAtMostOneLeft(): void
    {
        self::assertTrue($this->stats(['trialDossiersRemaining' => 1])->trialLow());
        self::assertTrue($this->stats(['trialDossiersRemaining' => 0])->trialLow());
        self::assertFalse($this->stats(['trialDossiersRemaining' => 2])->trialLow());
        self::assertFalse($this->stats(['trialDossiersRemaining' => 0, 'hasActiveSubscription' => true])->trialLow());
    }

    public function testMinutesLowNeedsAnAllocationAndFifteenOrFewerLeft(): void
    {
        self::assertTrue($this->stats(['meetingMinutesAllocated' => 90, 'remainingMeetingMinutes' => 10])->minutesLow());
        self::assertFalse($this->stats(['meetingMinutesAllocated' => 90, 'remainingMeetingMinutes' => 40])->minutesLow());
        self::assertFalse($this->stats(['meetingMinutesAllocated' => 0, 'remainingMeetingMinutes' => 0])->minutesLow());
    }
}
