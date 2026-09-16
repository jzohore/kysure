<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Screening\Entity\ScreeningAudit;

/**
 * Données agrégées du tableau de bord : compteurs de quota (Phase 1),
 * volumétrie des dossiers/clients, aperçus d'activité, et état de la
 * check-list d'onboarding.
 */
final readonly class UserDashboardStats
{
    /**
     * @param list<ComplianceFolder> $latestFolders
     * @param ScreeningAudit[]       $latestScreenings
     */
    public function __construct(
        public string $workspaceName,
        public bool $isFirm,
        // --- Quota / abonnement ---
        public bool $hasActiveSubscription,
        public bool $canOpenFolder,
        public int $trialDossiersRemaining,
        public int $remainingMeetingMinutes,
        public int $meetingMinutesAllocated,
        // --- Volumétrie ---
        public int $activeFoldersCount,
        public int $draftFoldersCount,
        public int $totalFoldersCount,
        public int $clientsCount,
        public int $teamMembersCount,
        public int $pendingScreeningsCount,
        // --- Dossiers à traiter (conseiller) ---
        public int $pendingDocsCount,
        public int $inReviewCount,
        public int $needsCorrectionCount,
        public int $approvedCount,
        public int $investorProfilesToValidateCount,
        // --- Aperçus d'activité ---
        public array $latestFolders,
        public array $latestScreenings,
        // --- Check-list d'onboarding ---
        public bool $isOrgCompleted,
        public bool $isRegProfileValid,
        public bool $is2faEnabled,
    ) {
    }

    public function hasFirstFolder(): bool
    {
        return $this->totalFoldersCount > 0;
    }

    /**
     * L'étape « collaborateurs / accès illimité » n'a de sens que pour un cabinet.
     */
    public function isTeamStepDone(): bool
    {
        return $this->hasActiveSubscription || $this->teamMembersCount > 1;
    }

    public function onboardingTotalSteps(): int
    {
        // 1 compte + 2 orga + 3 profil + 4 2FA + 5 premier dossier (+ 6 équipe si cabinet)
        return $this->isFirm ? 6 : 5;
    }

    public function onboardingDoneSteps(): int
    {
        $done = 1; // compte vérifié : toujours acquis
        $done += $this->isOrgCompleted ? 1 : 0;
        $done += $this->isRegProfileValid ? 1 : 0;
        $done += $this->is2faEnabled ? 1 : 0;
        $done += $this->hasFirstFolder() ? 1 : 0;
        $done += $this->isFirm && $this->isTeamStepDone() ? 1 : 0;

        return $done;
    }

    public function isOnboardingComplete(): bool
    {
        return $this->onboardingDoneSteps() >= $this->onboardingTotalSteps();
    }

    public function trialLow(): bool
    {
        return !$this->hasActiveSubscription && $this->trialDossiersRemaining <= 1;
    }

    public function minutesLow(): bool
    {
        return $this->meetingMinutesAllocated > 0
            && $this->remainingMeetingMinutes <= 15;
    }
}
