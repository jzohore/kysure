<?php

declare(strict_types=1);

namespace App\Tests\Application\Workspace\UseCase;

use App\Application\Workspace\UseCase\GetCurrentWorkspaceInfo;
use App\Domain\Firm\Entity\RegulatoryProfile;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class GetCurrentWorkspaceInfoTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testResultIsMemoizedWithinARequestThenForgottenOnReset(): void
    {
        $userId = Uuid::v7();

        $memberRepository = $this->createMock(WorkspaceMemberRepositoryInterface::class);
        // 2 lectures en base : 1 avant le reset (le 2e appel est servi par le cache) + 1 après.
        $memberRepository->expects($this->exactly(2))
            ->method('findOneByUser')
            ->willReturn($this->member());

        $useCase = new GetCurrentWorkspaceInfo($memberRepository);

        $first = $useCase($userId);
        self::assertSame($first, $useCase($userId));

        // 🛡️ Fin de requête en mode worker FrankenPHP (kernel.reset) : plus aucun état conservé.
        $useCase->reset();

        self::assertNotSame($first, $useCase($userId));
    }

    private function member(): WorkspaceMember
    {
        $workspace = $this->createEntityState(Workspace::class, [
            'id' => Uuid::v7(),
            'slugId' => 'workspace_abc',
            'name' => 'Cabinet Durand',
            'type' => WorkspaceType::FIRM,
            'isActive' => true,
            'isSiretValid' => true,
            'folders' => new ArrayCollection(),
            'regulatoryProfile' => $this->createEntityState(RegulatoryProfile::class),
            'subscription' => null,
            'meetingMinutesAllocated' => 90,
            'meetingSecondsConsumed' => 0,
        ]);

        return $this->createEntityState(WorkspaceMember::class, ['workspace' => $workspace]);
    }
}
