<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\ValidateInvestorProfileUseCase;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class ValidateInvestorProfileUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;
    private User $cgp;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $this->cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
    }

    private function submittedAssessment(): InvestorProfileAssessment
    {
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        foreach (QuestionKey::cases() as $key) {
            $assessment->recordAnswer($key, 'reponse', AnswerSource::CLIENT, $this->client->id);
        }
        $assessment->submit(['finalProfile' => 4, 'engineVersion' => 'suitability_engine_v1']);

        return $assessment;
    }

    public function testRefusesToValidateAnAssessmentThatIsNotYetSubmitted(): void
    {
        $draft = InvestorProfileAssessment::create($this->workspace, $this->client);

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $useCase = $this->buildUseCase($profileRepo);

        $this->expectException(\DomainException::class);

        ($useCase)($draft);
    }

    public function testRefusesToValidateWhenAProfileIsAlreadyInForce(): void
    {
        $assessment = $this->submittedAssessment();

        $existing = $this->createStub(ValidatedInvestorProfile::class);

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn($existing);

        $useCase = $this->buildUseCase($profileRepo);

        $this->expectException(\DomainException::class);

        ($useCase)($assessment);
    }

    public function testFreezesTheProfileIncrementsTheVersionAndDispatchesTheEvent(): void
    {
        $assessment = $this->submittedAssessment();

        $profileRepo = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn(null);
        $profileRepo->method('findLatestVersionNumber')->willReturn(2);
        $profileRepo->expects(self::once())->method('save');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            self::callback(static function (InvestorProfileValidatedEvent $event): bool {
                self::assertSame(3, $event->version);
                self::assertSame(4, $event->retainedProfileLevel);
                self::assertFalse($event->overridden);

                return true;
            }),
        );

        $useCase = $this->buildUseCase($profileRepo, $dispatcher);
        $slugId = ($useCase)($assessment);

        self::assertStringStartsWith('vip_', $slugId);
    }

    public function testAnOverriddenProfileIsReflectedInTheDispatchedEvent(): void
    {
        $assessment = $this->submittedAssessment();

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn(null);
        $profileRepo->method('findLatestVersionNumber')->willReturn(0);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            self::callback(static function (InvestorProfileValidatedEvent $event): bool {
                self::assertSame(2, $event->retainedProfileLevel);
                self::assertTrue($event->overridden);

                return true;
            }),
        );

        $useCase = $this->buildUseCase($profileRepo, $dispatcher);
        ($useCase)($assessment, 2, 'Capacité à subir des pertes surestimée.');
    }

    private function buildUseCase(
        ValidatedInvestorProfileRepositoryInterface $profileRepo,
        ?EventDispatcherInterface $dispatcher = null,
    ): ValidateInvestorProfileUseCase {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->cgp);

        return new ValidateInvestorProfileUseCase(
            $profileRepo,
            $transactionManager,
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }
}
