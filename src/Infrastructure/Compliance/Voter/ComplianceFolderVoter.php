<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Voter;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Enum\InvitedRole;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\WorkspacePermissionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ComplianceFolder>
 */
class ComplianceFolderVoter extends Voter
{
    public const string VIEW = 'VIEW';
    public const string EDIT = 'EDIT';
    public const string DELETE = 'DELETE';
    public const string MAKE_CONFIDENTIAL = 'MAKE_CONFIDENTIAL';
    public const string APPROVE = 'APPROVE_FOLDER';
    public const string REJECT = 'REJECT_FOLDER';

    /**
     * @var array<string, WorkspaceMember|null>
     */
    private array $memberCache = [];

    public function __construct(
        private readonly WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private readonly WorkspacePermissionChecker $permissionChecker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::MAKE_CONFIDENTIAL, self::APPROVE, self::REJECT], true)
            && $subject instanceof ComplianceFolder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var ComplianceFolder $folder */
        $folder = $subject;
        $workspace = $folder->workspace;

        // 🪄 1. On cherche la relation (le membre)
        $workspaceMember = $this->getWorkspaceMember($user, $workspace);

        // 🚨 S'il n'est pas membre de ce cabinet, accès refusé à TOUT !
        if (!$workspaceMember instanceof WorkspaceMember) {
            return false;
        }

        // 🪄 2. On délègue en passant l'entité membre qui contient le Rôle Contextuel
        return match ($attribute) {
            self::VIEW => $this->canView($folder, $user),
            self::EDIT => $this->canEdit($folder, $user),
            self::DELETE => $this->canDelete($workspaceMember),
            self::MAKE_CONFIDENTIAL => $this->canMakeConfidential($folder, $user, $workspaceMember),
            self::APPROVE, self::REJECT => $this->permissionChecker->canValidateActs($user, $workspace),
            default => false,
        };
    }

    private function getWorkspaceMember(User $user, Workspace $workspace): ?WorkspaceMember
    {
        $cacheKey = $user->id . '_' . $workspace->id;

        if (array_key_exists($cacheKey, $this->memberCache)) {
            return $this->memberCache[$cacheKey];
        }

        $member = $this->workspaceMemberRepository->findByWorkspaceAndUser($workspace, $user);
        $this->memberCache[$cacheKey] = $member;

        return $member;
    }

    private function canView(ComplianceFolder $folder, User $user): bool
    {
        return $folder->canBeViewedBy($user);
    }

    private function canEdit(ComplianceFolder $folder, User $user): bool
    {
        return $this->canView($folder, $user);
    }

    private function canDelete(WorkspaceMember $member): bool
    {
        return 'ROLE_WORKSPACE_ADMIN' === $member->role->value;
    }

    private function canMakeConfidential(ComplianceFolder $folder, User $user, WorkspaceMember $member): bool
    {
        // 🪄 Utilisation d'un match expression (Plus lisible, DDD-compliant et extensible)
        return match ($member->role) {
            InvitedRole::ROLE_WORKSPACE_ADMIN => true,
            InvitedRole::ROLE_WORKSPACE_COLLAB => $this->canView($folder, $user),
        };
    }
}
