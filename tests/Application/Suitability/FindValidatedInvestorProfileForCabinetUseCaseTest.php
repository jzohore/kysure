<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\FindValidatedInvestorProfileForCabinetUseCase;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;

final class FindValidatedInvestorProfileForCabinetUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testDelegatesToTheWorkspaceScopedRepositoryLookup(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $profile = $this->createStub(ValidatedInvestorProfile::class);

        $repository = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('findBySlugIdAndWorkspace')
            ->with('vip_1', $workspace)
            ->willReturn($profile);

        $useCase = new FindValidatedInvestorProfileForCabinetUseCase($repository);

        self::assertSame($profile, ($useCase)('vip_1', $workspace));
    }
}
