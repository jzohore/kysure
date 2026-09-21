<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\GetOrCreateDraftAssessmentUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
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
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn($existingDraft);
        $assessmentRepo->expects(self::never())->method('save');

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);

        self::assertSame($existingDraft, ($useCase)($this->client));
    }

    public function testStartsANewDraftFromTheClientsActiveFolderWorkspace(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);
        $assessmentRepo->expects(self::once())->method('save');

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn(null);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch');

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);
        $assessment = ($useCase)($this->client);

        self::assertSame($this->workspace, $assessment->workspace);
        self::assertSame($this->client, $assessment->client);
    }

    public function testRefusesToStartAnAssessmentWithoutAnActiveFolder(): void
    {
        $assessmentRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn(null);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn(null);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);

        $this->expectException(\LogicException::class);

        ($useCase)($this->client);
    }

    public function testRefusesToStartANewDraftWhenAnInForceValidatedProfileExists(): void
    {
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $submittedAssessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        $validatedProfile = ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $submittedAssessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 4]],
            version: 1,
        );
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);
        $assessmentRepo->expects(self::never())->method('save');

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn($validatedProfile);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);

        $this->expectException(\DomainException::class);

        ($useCase)($this->client);
    }

    public function testPrefillsTheNewDraftFromTheMostRecentSubmissionAcrossWorkspaces(): void
    {
        $otherWorkspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_2', 'name' => 'Autre cabinet']);
        $priorSubmission = InvestorProfileAssessment::create($otherWorkspace, $this->client);
        $priorSubmission->recordAnswer(QuestionKey::CAPACITY_ANNUAL_INCOME, 45000, AnswerSource::CLIENT, $this->client->id);

        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);
        $assessmentRepo->method('findLatestSubmittedForClient')->willReturn(null);
        $assessmentRepo->method('findMostRecentSubmittedAcrossWorkspaces')->willReturn($priorSubmission);
        $assessmentRepo->expects(self::once())->method('save');

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn(null);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);
        $assessment = ($useCase)($this->client);

        self::assertSame(45000, $assessment->getAnswerValue(QuestionKey::CAPACITY_ANNUAL_INCOME));
        self::assertTrue($assessment->isAnswerFromPrefill(QuestionKey::CAPACITY_ANNUAL_INCOME));
    }

    public function testResolvesTheExplicitFolderWhenProvidedRatherThanTheMostRecentOne(): void
    {
        // Un client peut avoir plusieurs cabinets actifs à la fois : le folderId (carte
        // cliquée sur le tableau de bord) doit primer sur "le dossier le plus récent" —
        // vérifié ci-dessous via l'assertion que findActiveForClient n'est jamais appelée.
        $requestedFolder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $assessmentRepo = $this->createMock(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn(null);
        $assessmentRepo->method('findLatestSubmittedForClient')->willReturn(null);
        $assessmentRepo->expects(self::once())->method('save');

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn(null);

        $folderRepo = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $folderRepo->expects(self::once())->method('findOneBySlugIdAndClient')->with('fld_requested', $this->client)->willReturn($requestedFolder);
        $folderRepo->expects(self::never())->method('findActiveForClient');

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new GetOrCreateDraftAssessmentUseCase($assessmentRepo, $validatedProfileRepo, $folderRepo, $dispatcher);
        $assessment = ($useCase)($this->client, 'fld_requested');

        self::assertSame($this->workspace, $assessment->workspace);
    }
}
