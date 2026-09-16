<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\RevokeInvestorProfileUseCase;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileRevokedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class RevokeInvestorProfileUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private User $cgp;
    private ValidatedInvestorProfile $profile;

    protected function setUp(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        $this->profile = ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $this->cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 4]],
            version: 1,
        );
    }

    public function testThrowsWhenTheProfileDoesNotExist(): void
    {
        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findBySlugId')->willReturn(null);

        $useCase = $this->buildUseCase($profileRepo);

        $this->expectException(\DomainException::class);

        ($useCase)('vip_unknown', 'Motif');
    }

    public function testRevokesTheProfileAndDispatchesTheEvent(): void
    {
        $profileRepo = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findBySlugId')->willReturn($this->profile);
        $profileRepo->expects(self::once())->method('save')->with($this->profile);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            self::callback(static function (InvestorProfileRevokedEvent $event): bool {
                self::assertSame(1, $event->version);
                self::assertSame('Nouvelle tentative après correction.', $event->reason);

                return true;
            }),
        );

        $useCase = $this->buildUseCase($profileRepo, $dispatcher);
        ($useCase)($this->profile->slugId, 'Nouvelle tentative après correction.');

        self::assertTrue($this->profile->isRevoked());
    }

    private function buildUseCase(
        ValidatedInvestorProfileRepositoryInterface $profileRepo,
        ?EventDispatcherInterface $dispatcher = null,
    ): RevokeInvestorProfileUseCase {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->cgp);

        return new RevokeInvestorProfileUseCase(
            $profileRepo,
            $transactionManager,
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }
}
