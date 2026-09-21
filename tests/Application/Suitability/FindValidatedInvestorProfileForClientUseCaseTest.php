<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\FindValidatedInvestorProfileForClientUseCase;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FindValidatedInvestorProfileForClientUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private function profileFor(Client $client): ValidatedInvestorProfile
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        return ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 3]],
            version: 1,
        );
    }

    public function testReturnsTheProfileWhenItBelongsToTheRequestingClient(): void
    {
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $profile = $this->profileFor($client);

        $repository = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $repository->method('findBySlugId')->willReturn($profile);

        $useCase = new FindValidatedInvestorProfileForClientUseCase($repository);

        self::assertSame($profile, ($useCase)($profile->slugId, $client));
    }

    public function testReturnsNullWhenTheProfileBelongsToAnotherClient(): void
    {
        $owner = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $otherClient = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_2', 'email' => 'c@d.fr', 'firstName' => 'C', 'lastName' => 'D']);
        $profile = $this->profileFor($owner);

        $repository = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $repository->method('findBySlugId')->willReturn($profile);

        $useCase = new FindValidatedInvestorProfileForClientUseCase($repository);

        self::assertNull(($useCase)($profile->slugId, $otherClient));
    }

    public function testReturnsNullWhenNoProfileMatchesTheSlug(): void
    {
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);

        $repository = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $repository->method('findBySlugId')->willReturn(null);

        $useCase = new FindValidatedInvestorProfileForClientUseCase($repository);

        self::assertNull(($useCase)('vip_unknown', $client));
    }
}
