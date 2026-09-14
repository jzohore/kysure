<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Voter;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Autorise la consultation du détail d'un ticket de support : un super-admin
 * KYSURE (support), ou un membre du cabinet propriétaire du thread — jamais
 * un membre d'un autre cabinet.
 *
 * @extends Voter<string, SupportThread>
 */
class SupportThreadVoter extends Voter
{
    public const string VIEW = 'VIEW';

    public function __construct(
        private readonly WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof SupportThread;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (in_array('ROLE_SUPER_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var SupportThread $thread */
        $thread = $subject;

        return $this->workspaceMemberRepository->findByWorkspaceAndUser($thread->workspace, $user) instanceof WorkspaceMember;
    }
}
