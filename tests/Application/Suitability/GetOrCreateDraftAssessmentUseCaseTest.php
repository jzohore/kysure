<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\GetOrCreateDraftAssessmentUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class GetOrCreateDraftAssessmentUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Client $client;
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet']);
        $this->client = $this->createEntityState(Client::class, [
            'id' => Uuid::v7(),
            'slugId' => 'cli_1',
            'email' => 'jean@example.com',
        ]);
    }

    public function testReusesTheExistingActiveDraftWithoutTouchingAnything(): void
    {
        $existingDraft = InvestorProfileAssessment::create($this->workspace, $this->client);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn($existingDraft);
        $assessmentRepo->expects(self::never())->method('save');

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $folderRepo, $dispatcher);

        self::assertSame($existingDraft, ($useCase)($this->client));
    }

    public function testStartsANewDraftFromTheClientsActiveFolderWorkspace(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);
        $assessmentRepo->expects(self::once())->method('save');

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch');

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $folderRepo, $dispatcher);
        $assessment = ($useCase)($this->client);

        self::assertSame($this->workspace, $assessment->workspace);
        self::assertSame($this->client, $assessment->client);
    }

    public function testRefusesToStartAnAssessmentWithoutAnActiveFolder(): void
    {
        $assessmentRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn(null);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $folderRepo, $dispatcher);

        $this->expectException(\LogicException::class);

        ($useCase)($this->client);
    }
}
