<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance;

use App\Application\Compliance\UseCase\ComplianceFolder\ApproveComplianceFolderUseCase;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\RiskLevel;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Exception\FolderStateException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class ApproveComplianceFolderUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private Workspace $workspace;
    private Client $client;
    private User $reviewer;
    private ComplianceFolderRepositoryInterface&MockObject $folderRepository;
    private ValidatedInvestorProfileRepositoryInterface&Stub $profileRepository;
    private AdequacyReportRepositoryInterface&Stub $reportRepository;

    protected function setUp(): void
    {
        $this->workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $this->client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'jean@example.com']);
        $this->reviewer = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $this->folderRepository = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $this->profileRepository = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $this->reportRepository = $this->createStub(AdequacyReportRepositoryInterface::class);
    }

    /** Rapport en vigueur ; `$profileRevoked` simule un profil révoqué après sa validation. */
    private function report(bool $profileRevoked = false): AdequacyReport
    {
        return $this->createEntityState(AdequacyReport::class, [
            'revokedAt' => null,
            'investorProfile' => $this->createEntityState(ValidatedInvestorProfile::class, ['revokedAt' => $profileRevoked ? new \DateTimeImmutable() : null]),
        ]);
    }

    private function folder(): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'workspace' => $this->workspace,
            'client' => $this->client,
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-001',
            'status' => ComplianceFolderStatus::IN_REVIEW,
            'history' => [],
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
            'documents' => new ArrayCollection(),
        ]);
    }

    private function buildUseCase(?EventDispatcherInterface $dispatcher = null): ApproveComplianceFolderUseCase
    {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb) => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->reviewer);

        return new ApproveComplianceFolderUseCase(
            $this->folderRepository,
            $this->profileRepository,
            $this->reportRepository,
            $transactionManager,
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    public function testRefusesToApproveWithoutAnInForceValidatedInvestorProfile(): void
    {
        $this->profileRepository->method('findInForceByClient')->willReturn(null);
        $this->folderRepository->expects($this->never())->method('save');

        $useCase = $this->buildUseCase();

        $this->expectException(FolderStateException::class);
        ($useCase)($this->folder(), RiskLevel::MEDIUM);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRefusesToApproveWhenTheFolderHasNoClientAttached(): void
    {
        $orphanFolder = $this->createEntityState(IndividualFolder::class, [
            'workspace' => $this->workspace,
            'client' => null,
            'reference' => 'DOS-2026-002',
            'status' => ComplianceFolderStatus::IN_REVIEW,
            'history' => [],
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
            'documents' => new ArrayCollection(),
        ]);

        $useCase = $this->buildUseCase();

        $this->expectException(FolderStateException::class);
        ($useCase)($orphanFolder, RiskLevel::MEDIUM);
    }

    public function testApprovesAndDispatchesTheEventWhenAProfileIsInForce(): void
    {
        $profile = $this->createStub(ValidatedInvestorProfile::class);
        $this->profileRepository->method('findInForceByClient')->willReturn($profile);
        $this->reportRepository->method('findInForceByFolder')->willReturn($this->report());

        $folder = $this->folder();
        $this->folderRepository->expects($this->once())->method('save')->with($folder);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with(
            $this->callback(static function (ComplianceFolderApprovedEvent $event) use ($folder): bool {
                self::assertSame($folder->slugId, $event->folderSlugId);
                self::assertSame('HIGH', $event->riskLevel);
                self::assertSame('Marie CURIE', $event->approvedByName);

                return true;
            }),
        );

        $useCase = $this->buildUseCase($dispatcher);
        ($useCase)($folder, RiskLevel::HIGH, 'Dossier complet.');

        self::assertSame(ComplianceFolderStatus::APPROVED, $folder->status);
    }

    public function testRefusesToApproveAnIndividualFolderWithoutAValidatedAdequacyReport(): void
    {
        $this->profileRepository->method('findInForceByClient')->willReturn($this->createStub(ValidatedInvestorProfile::class));
        $this->reportRepository->method('findInForceByFolder')->willReturn(null);
        $this->folderRepository->expects($this->never())->method('save');

        $this->expectException(FolderStateException::class);
        $this->expectExceptionMessageMatches('/rapport d\'adéquation/');

        ($this->buildUseCase())($this->folder(), RiskLevel::MEDIUM);
    }

    public function testRefusesAReportWhoseInvestorProfileWasRevokedSince(): void
    {
        $this->profileRepository->method('findInForceByClient')->willReturn($this->createStub(ValidatedInvestorProfile::class));
        $this->reportRepository->method('findInForceByFolder')->willReturn($this->report(profileRevoked: true));
        $this->folderRepository->expects($this->never())->method('save');

        $this->expectException(FolderStateException::class);

        ($this->buildUseCase())($this->folder(), RiskLevel::MEDIUM);
    }

    public function testBusinessFolderDoesNotNeedAnAdequacyReport(): void
    {
        $this->profileRepository->method('findInForceByClient')->willReturn($this->createStub(ValidatedInvestorProfile::class));
        $this->reportRepository->method('findInForceByFolder')->willReturn(null);

        $folder = $this->createEntityState(BusinessFolder::class, [
            'workspace' => $this->workspace,
            'client' => $this->client,
            'slugId' => 'comp_fol_2',
            'reference' => 'KYB-2026-001',
            'status' => ComplianceFolderStatus::IN_REVIEW,
            'history' => [],
            'createdAt' => new \DateTimeImmutable('2026-01-01'),
            'documents' => new ArrayCollection(),
        ]);
        $this->folderRepository->expects($this->once())->method('save')->with($folder);

        ($this->buildUseCase())($folder, RiskLevel::LOW);

        self::assertSame(ComplianceFolderStatus::APPROVED, $folder->status);
    }
}
