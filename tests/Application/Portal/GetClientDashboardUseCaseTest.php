<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Application\Portal\DTO\ClientDashboardDto;
use App\Application\Portal\UseCase\GetClientDashboardUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileDashboardStatus;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class GetClientDashboardUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;
    private IndividualFolder $folder;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A', 'email' => 'contact@cabinet-a.fr']);
        $this->client = $this->createEntityState(Client::class, [
            'id' => Uuid::v7(),
            'slugId' => 'cli_1',
            'email' => 'jean@example.com',
            'firstName' => 'Jean',
            'workspaces' => new ArrayCollection([$this->workspace]),
        ]);
        $this->folder = $this->createEntityState(IndividualFolder::class, [
            'workspace' => $this->workspace,
            'status' => ComplianceFolderStatus::APPROVED,
            'slugId' => 'fld_1',
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
        ]);
    }

    public function testThrowsWhenTheClientHasNoActiveFolderAtAll(): void
    {
        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findAllActiveForClient')->willReturn([]);

        $assessmentRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $useCase = new GetClientDashboardUseCase($documentRepo, $folderRepo, $assessmentRepo, $validatedProfileRepo, $logger);

        $this->expectException(\LogicException::class);

        ($useCase)($this->client);
    }

    public function testInvestorProfileStatusIsNotStartedByDefault(): void
    {
        $relationship = $this->buildAndInvoke()->cabinetRelationships[0];

        self::assertSame(InvestorProfileDashboardStatus::NOT_STARTED, $relationship->investorProfileStatus);
    }

    public function testInvestorProfileStatusIsInProgressWhenADraftExists(): void
    {
        $draft = InvestorProfileAssessment::create($this->workspace, $this->client);

        $relationship = $this->buildAndInvoke(draft: $draft)->cabinetRelationships[0];

        self::assertSame(InvestorProfileDashboardStatus::IN_PROGRESS, $relationship->investorProfileStatus);
    }

    public function testInvestorProfileStatusIsSubmittedWhenPendingCgpReview(): void
    {
        $submitted = InvestorProfileAssessment::create($this->workspace, $this->client);

        $relationship = $this->buildAndInvoke(submitted: $submitted)->cabinetRelationships[0];

        self::assertSame(InvestorProfileDashboardStatus::SUBMITTED, $relationship->investorProfileStatus);
    }

    public function testInvestorProfileStatusIsValidatedWhenAnInForceProfileExists(): void
    {
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($this->workspace, $this->client);
        $profile = ValidatedInvestorProfile::validate(
            $this->workspace,
            $this->client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 4]],
            version: 1,
        );

        $relationship = $this->buildAndInvoke(validated: $profile)->cabinetRelationships[0];

        self::assertSame(InvestorProfileDashboardStatus::VALIDATED, $relationship->investorProfileStatus);
    }

    public function testReturnsOneRelationshipPerActiveCabinetWhenTheClientHasSeveral(): void
    {
        $otherWorkspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_2', 'name' => 'Cabinet B', 'email' => 'contact@cabinet-b.fr']);
        $otherFolder = $this->createEntityState(IndividualFolder::class, [
            'workspace' => $otherWorkspace,
            'status' => ComplianceFolderStatus::AWAITING_CLIENT,
            'slugId' => 'fld_2',
            'createdAt' => new \DateTimeImmutable('2026-02-01'),
        ]);

        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $documentRepo->method('countPendingForFolder')->willReturn(0);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findAllActiveForClient')->willReturn([$this->folder, $otherFolder]);

        $assessmentRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $useCase = new GetClientDashboardUseCase($documentRepo, $folderRepo, $assessmentRepo, $validatedProfileRepo, $logger);
        $dashboard = ($useCase)($this->client);

        self::assertCount(2, $dashboard->cabinetRelationships);
        self::assertSame('Cabinet A', $dashboard->cabinetRelationships[0]->activeFolder->workspaceName);
        self::assertSame('Cabinet B', $dashboard->cabinetRelationships[1]->activeFolder->workspaceName);
    }

    private function buildAndInvoke(
        ?InvestorProfileAssessment $draft = null,
        ?InvestorProfileAssessment $submitted = null,
        ?ValidatedInvestorProfile $validated = null,
    ): ClientDashboardDto {
        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $documentRepo->method('countPendingForFolder')->willReturn(0);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findAllActiveForClient')->willReturn([$this->folder]);

        $assessmentRepo = $this->createStub(InvestorProfileAssessmentRepositoryInterface::class);
        $assessmentRepo->method('findActiveDraftForClient')->willReturn($draft);
        $assessmentRepo->method('findLatestSubmittedForClient')->willReturn($submitted);

        $validatedProfileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $validatedProfileRepo->method('findInForceByClient')->willReturn($validated);

        $logger = $this->createStub(LoggerInterface::class);

        $useCase = new GetClientDashboardUseCase($documentRepo, $folderRepo, $assessmentRepo, $validatedProfileRepo, $logger);

        return ($useCase)($this->client);
    }
}
