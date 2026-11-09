<?php

declare(strict_types=1);

namespace App\Tests\Application\AdequacyReport;

use App\Application\AdequacyReport\UseCase\RevokeAdequacyReportUseCase;
use App\Domain\AdequacyReport\Event\AdequacyReportRevokedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RevokeAdequacyReportUseCaseTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testRevokesSavesAndDispatchesTheReason(): void
    {
        $report = $this->validateReport();

        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->expects(self::once())->method('save')->with($report);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(static function (AdequacyReportRevokedEvent $event) use ($report): bool {
            self::assertSame($report->slugId, $event->reportSlugId);
            self::assertSame('Autre produit retenu avec le client.', $event->reason);

            return true;
        }))->willReturnArgument(0);

        ($this->buildUseCase($reportRepo, $dispatcher))($report, '  Autre produit retenu avec le client. ');

        self::assertTrue($report->isRevoked());
        self::assertSame('Rapport d\'adéquation révoqué', $this->folder->history[array_key_last($this->folder->history)]['title']);
    }

    public function testNothingIsSavedNorDispatchedWithoutAReason(): void
    {
        $report = $this->validateReport();

        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->expects(self::never())->method('save');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->expectException(\DomainException::class);

        ($this->buildUseCase($reportRepo, $dispatcher))($report, ' ');
    }

    private function buildUseCase(AdequacyReportRepositoryInterface $reportRepo, EventDispatcherInterface $dispatcher): RevokeAdequacyReportUseCase
    {
        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb): mixed => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->cgp);

        return new RevokeAdequacyReportUseCase(
            $reportRepo,
            $this->createStub(ComplianceFolderRepositoryInterface::class),
            $transactionManager,
            $userProvider,
            $dispatcher,
        );
    }
}
