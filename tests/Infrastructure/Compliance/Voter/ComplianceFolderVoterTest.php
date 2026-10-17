<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Voter;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Enum\InvitedRole;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\WorkspacePermissionChecker;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

final class ComplianceFolderVoterTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['id' => Uuid::v7()]);
        $this->user = $this->createEntityState(User::class, ['id' => Uuid::v7()]);
    }

    public function testMembershipIsMemoizedWithinARequestThenForgottenOnReset(): void
    {
        $admin = $this->createEntityState(WorkspaceMember::class, ['role' => InvitedRole::ROLE_WORKSPACE_ADMIN]);

        $memberRepository = $this->createMock(WorkspaceMemberRepositoryInterface::class);
        // 2 lectures en base seulement : 1 avant le reset (le 2e vote est servi par le cache) + 1 après.
        $memberRepository->expects($this->exactly(2))->method('findByWorkspaceAndUser')->willReturn($admin);

        $voter = new ComplianceFolderVoter($memberRepository, $this->createStub(WorkspacePermissionChecker::class));
        $token = $this->token();

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($token, $this->folder(), [ComplianceFolderVoter::DELETE]));
        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($token, $this->folder(), [ComplianceFolderVoter::DELETE]));

        $voter->reset();

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($token, $this->folder(), [ComplianceFolderVoter::DELETE]));
    }

    public function testRevokedMemberLosesAccessOnceTheWorkerResetsBetweenRequests(): void
    {
        $admin = $this->createEntityState(WorkspaceMember::class, ['role' => InvitedRole::ROLE_WORKSPACE_ADMIN]);

        $memberRepository = $this->createStub(WorkspaceMemberRepositoryInterface::class);
        $memberRepository->method('findByWorkspaceAndUser')->willReturnOnConsecutiveCalls($admin, null);

        $voter = new ComplianceFolderVoter($memberRepository, $this->createStub(WorkspacePermissionChecker::class));
        $token = $this->token();

        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($token, $this->folder(), [ComplianceFolderVoter::DELETE]));

        // 🛡️ Fin de requête (kernel.reset) : le membre a été révoqué entre-temps.
        $voter->reset();

        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($token, $this->folder(), [ComplianceFolderVoter::DELETE]));
    }

    private function folder(): BusinessFolder
    {
        return $this->createEntityState(BusinessFolder::class, [
            'workspace' => $this->workspace,
            'isConfidential' => false,
        ]);
    }

    private function token(): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($this->user);

        return $token;
    }
}
