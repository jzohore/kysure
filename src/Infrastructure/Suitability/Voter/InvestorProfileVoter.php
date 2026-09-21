<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Voter;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\WorkspacePermissionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorise la validation et la révocation du profil investisseur calculé d'un client.
 *
 * Règle : être membre du cabinet propriétaire de l'assessment ET avoir le droit de valider
 * des actes de conformité pour ce cabinet (même règle que {@see \App\Infrastructure\Compliance\Voter\MeetingReportVoter}).
 *
 * @extends Voter<string, InvestorProfileAssessment>
 */
class InvestorProfileVoter extends Voter
{
    public const string VALIDATE = 'VALIDATE_INVESTOR_PROFILE';
    public const string REVOKE = 'REVOKE_INVESTOR_PROFILE';

    public function __construct(
        private readonly WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private readonly WorkspacePermissionChecker $permissionChecker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VALIDATE, self::REVOKE], true)
            && $subject instanceof InvestorProfileAssessment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var InvestorProfileAssessment $assessment */
        $assessment = $subject;

        if (!$this->isMemberOfWorkspace($user, $assessment->workspace)) {
            return false;
        }

        return match ($attribute) {
            self::VALIDATE, self::REVOKE => $this->permissionChecker->canValidateActs($user, $assessment->workspace),
            default => false,
        };
    }

    private function isMemberOfWorkspace(User $user, Workspace $workspace): bool
    {
        return $this->workspaceMemberRepository->findByWorkspaceAndUser($workspace, $user) instanceof WorkspaceMember;
    }
}
