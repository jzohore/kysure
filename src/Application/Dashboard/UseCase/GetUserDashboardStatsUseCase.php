<?php

declare(strict_types=1);

namespace App\Application\Dashboard\UseCase;

use App\Application\Dashboard\DTO\UserDashboardStats;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Firm\Entity\RegulatoryProfile;
use App\Domain\Firm\Repository\RegulatoryProfileRepositoryInterface;
use App\Domain\Screening\Repository\ScreeningAuditRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Repository\ClientRepositoryInterface;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;

readonly class GetUserDashboardStatsUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private ClientRepositoryInterface $clientRepository,
        private WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private RegulatoryProfileRepositoryInterface $regulatoryProfileRepository,
        private ScreeningAuditRepositoryInterface $screeningAuditRepository,
        private ValidatedInvestorProfileRepositoryInterface $validatedInvestorProfileRepository,
        private CurrentWorkspaceProvider $workspaceProvider,
        private CurrentUserProvider $userProvider,
    ) {
    }

    public function __invoke(): UserDashboardStats
    {
        $workspace = $this->workspaceProvider->getWorkspace();
        $user = $this->userProvider->getUser();

        $profile = $this->regulatoryProfileRepository->findOneByWorkspace($workspace);
        $isRegProfileValid = $profile instanceof RegulatoryProfile && $profile->isProfileValid();

        // 1 seule requête GROUP BY au lieu de 7 COUNT : tous les compteurs de dossiers en découlent.
        $byStatus = $this->complianceFolderRepository->countsByStatusForWorkspace($workspace);
        $count = static fn (ComplianceFolderStatus $status): int => $byStatus[$status->value] ?? 0;

        // « En cours » = ni brouillon, ni supprimé, ni archivé, ni rejeté ; « total » = tout sauf supprimé.
        $notActive = [ComplianceFolderStatus::DRAFT, ComplianceFolderStatus::DELETED, ComplianceFolderStatus::ARCHIVED, ComplianceFolderStatus::REJECTED];
        $activeCount = array_sum($byStatus) - array_sum(array_map($count, $notActive));
        $totalCount = array_sum($byStatus) - $count(ComplianceFolderStatus::DELETED);

        return new UserDashboardStats(
            workspaceName: $workspace->name,
            isFirm: $workspace->isFirm(),
            hasActiveSubscription: $workspace->hasActiveSubscription(),
            canOpenFolder: $workspace->canOpenComplianceFolder(),
            trialDossiersRemaining: $workspace->trialDossiersRemaining,
            remainingMeetingMinutes: $workspace->remainingMeetingMinutes(),
            meetingMinutesAllocated: $workspace->meetingMinutesAllocated,
            activeFoldersCount: $activeCount,
            draftFoldersCount: $count(ComplianceFolderStatus::DRAFT),
            totalFoldersCount: $totalCount,
            clientsCount: $this->clientRepository->findAllByWorkspace($workspace, null, 'recent', true)->getNbResults(),
            teamMembersCount: $this->workspaceMemberRepository->countByWorkspace($workspace),
            pendingScreeningsCount: $this->screeningAuditRepository->countInProgressForWorkspace($workspace),
            pendingDocsCount: $count(ComplianceFolderStatus::PENDING_DOCS),
            inReviewCount: $count(ComplianceFolderStatus::IN_REVIEW),
            needsCorrectionCount: $count(ComplianceFolderStatus::NEEDS_CORRECTION),
            approvedCount: $count(ComplianceFolderStatus::APPROVED),
            investorProfilesToValidateCount: $this->validatedInvestorProfileRepository->countPendingValidationForWorkspace($workspace),
            latestFolders: $this->complianceFolderRepository->findRecentByWorkspace($workspace, 5),
            latestScreenings: $this->screeningAuditRepository->findRecentByWorkspace($workspace, 5),
            pendingInvestorProfileValidations: $this->validatedInvestorProfileRepository->findPendingValidationForWorkspace($workspace),
            isOrgCompleted: $workspace->isOrgCompleted(),
            isRegProfileValid: $isRegProfileValid,
            is2faEnabled: $user->isGoogleAuthenticatorEnabled(),
        );
    }
}
