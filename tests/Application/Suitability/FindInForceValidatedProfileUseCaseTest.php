<?php

declare(strict_types=1);

namespace App\Tests\Application\Suitability;

use App\Application\Suitability\UseCase\FindInForceValidatedProfileUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FindInForceValidatedProfileUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
    }

    public function testReturnsNullWhenClientHasNoActiveFolder(): void
    {
        $profileRepo = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->expects(self::never())->method('findInForceByClient');

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn(null);

        $useCase = new FindInForceValidatedProfileUseCase($profileRepo, $folderRepo);

        self::assertNull(($useCase)($this->client));
    }

    public function testScopesTheLookupToTheClientsCurrentActiveWorkspace(): void
    {
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);
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

        $profileRepo = $this->createMock(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->expects(self::once())->method('findInForceByClient')
            ->with($this->client, $this->workspace)
            ->willReturn($profile);

        $folderRepo = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepo->method('findActiveForClient')->willReturn($folder);

        $useCase = new FindInForceValidatedProfileUseCase($profileRepo, $folderRepo);

        self::assertSame($profile, ($useCase)($this->client));
    }

    public function testResolvesTheExplicitFolderWhenProvidedRatherThanTheMostRecentOne(): void
    {
        // Un client peut avoir plusieurs cabinets actifs à la fois : le folderId (carte
        // cliquée sur le tableau de bord) doit primer sur "le dossier le plus récent".
        $requestedFolder = $this->createEntityState(IndividualFolder::class, ['workspace' => $this->workspace]);

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn(null);

        $folderRepo = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $folderRepo->expects(self::once())->method('findOneBySlugIdAndClient')->with('fld_requested', $this->client)->willReturn($requestedFolder);
        $folderRepo->expects(self::never())->method('findActiveForClient');

        $useCase = new FindInForceValidatedProfileUseCase($profileRepo, $folderRepo);

        ($useCase)($this->client, 'fld_requested');
    }
}
