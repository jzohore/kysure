<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Voter;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\WorkspacePermissionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorisation du rapport d'adéquation, côté cabinet.
 *
 * - VIEW (sujet : rapport) : membre du cabinet du dossier ET dossier visible
 *   ({@see ComplianceFolder::canBeViewedBy()}, liste blanche de confidentialité).
 * - VALIDATE (sujet : dossier) et REVOKE (sujet : rapport) : VIEW + droit de valider des actes
 *   de conformité pour ce cabinet — même règle que la validation du profil investisseur.
 *
 * Le client, lui, n'entre jamais ici : son accès passe par la propriété du rapport
 * (voir {@see \App\Application\AdequacyReport\UseCase\OpenAdequacyReportForClientUseCase}).
 *
 * @extends Voter<string, AdequacyReport|ComplianceFolder>
 */
class AdequacyReportVoter extends Voter
{
    public const string VIEW = 'VIEW_ADEQUACY_REPORT';
    public const string VALIDATE = 'VALIDATE_ADEQUACY_REPORT';
    public const string REVOKE = 'REVOKE_ADEQUACY_REPORT';

    public function __construct(
        private readonly WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
        private readonly WorkspacePermissionChecker $permissionChecker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW, self::REVOKE => $subject instanceof AdequacyReport,
            self::VALIDATE => $subject instanceof ComplianceFolder,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $folder = $subject instanceof AdequacyReport ? $subject->complianceFolder : $subject;

        $isMember = $this->workspaceMemberRepository->findByWorkspaceAndUser($folder->workspace, $user) instanceof WorkspaceMember;
        if (!$isMember || !$folder->canBeViewedBy($user)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::VALIDATE, self::REVOKE => $this->permissionChecker->canValidateActs($user, $folder->workspace),
            default => false,
        };
    }
}
