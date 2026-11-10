<?php

declare(strict_types=1);

namespace App\Tests\Application\AdequacyReport;

use App\Application\AdequacyReport\UseCase\AdequacyReportDraftComposer;
use App\Application\AdequacyReport\UseCase\ValidateAdequacyReportUseCase;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Event\AdequacyReportValidatedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ValidateAdequacyReportUseCaseTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    private const string RATIONALE = 'Le contrat répond à l\'objectif de retraite à 8 ans, avec un risque cohérent avec le profil équilibré du client.';

    private ProductAdequacyCheck $check;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
        $this->check = $this->check();
    }

    public function testValidatesTheNextVersionAndDispatchesTheSealedHash(): void
    {
        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->method('findInForceByFolder')->willReturn(null);
        $reportRepo->method('findLatestVersionNumber')->willReturn(2);
        $saved = null;
        $reportRepo->expects(self::once())->method('save')->willReturnCallback(static function (AdequacyReport $report) use (&$saved): void {
            $saved = $report;
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(function (AdequacyReportValidatedEvent $event) use (&$saved): bool {
            self::assertInstanceOf(AdequacyReport::class, $saved);
            self::assertSame($saved->slugId, $event->reportSlugId);
            self::assertSame($saved->contentHash, $event->contentHash);
            self::assertSame(3, $event->version);
            self::assertSame(1, $event->checkCount);
            self::assertSame($this->profile->slugId, $event->investorProfileSlugId);

            return true;
        }))->willReturnArgument(0);

        $slugId = ($this->buildUseCase($reportRepo, $dispatcher))($this->folder, [$this->check->slugId], self::RATIONALE, true);

        self::assertInstanceOf(AdequacyReport::class, $saved);
        self::assertSame($slugId, $saved->slugId);
        self::assertSame(3, $saved->version);
        self::assertSame(self::RATIONALE, $saved->content['advisorRationale']);
        self::assertSame('Rapport d\'adéquation validé', $this->folder->history[array_key_last($this->folder->history)]['title']);
    }

    public function testRefusesASecondReportInForceForTheSameFolder(): void
    {
        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->method('findInForceByFolder')->willReturn($this->validateReport([$this->check]));
        $reportRepo->expects(self::never())->method('save');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/déjà en vigueur/');

        ($this->buildUseCase($reportRepo))($this->folder, [$this->check->slugId], self::RATIONALE, true);
    }

    public function testUnknownCheckIsRejected(): void
    {
        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->method('findInForceByFolder')->willReturn(null);
        $reportRepo->expects(self::never())->method('save');

        $this->expectException(\DomainException::class);

        ($this->buildUseCase($reportRepo))($this->folder, ['adq_inconnue'], self::RATIONALE, true);
    }

    public function testClientWhoAcknowledgedAnOlderDerHasNotConsentedToElectronicDelivery(): void
    {
        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->method('findInForceByFolder')->willReturn(null);
        $reportRepo->expects(self::never())->method('save');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/remise électronique/');

        ($this->buildUseCase($reportRepo, derStatementVersion: '2026-09-01'))($this->folder, [$this->check->slugId], self::RATIONALE, true);
    }

    private function buildUseCase(AdequacyReportRepositoryInterface $reportRepo, ?EventDispatcherInterface $dispatcher = null, string $derStatementVersion = DerStatement::VERSION): ValidateAdequacyReportUseCase
    {
        $acknowledgement = $this->createEntityState(DerAcknowledgement::class, [
            'slugId' => 'der_ack_1',
            'acknowledgedAt' => new \DateTimeImmutable('2026-11-01 10:00'),
            'statementVersion' => $derStatementVersion,
            'revokedAt' => null,
        ]);
        $der = $this->createEntityState(ComplianceDocument::class, ['acknowledgements' => new ArrayCollection([$acknowledgement])]);
        $documentRepo = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $documentRepo->method('findDerByFolder')->willReturn($der);

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findInForceByClient')->willReturn($this->profile);

        $checkRepo = $this->createStub(ProductAdequacyCheckRepositoryInterface::class);
        $checkRepo->method('findBySlugId')->willReturnCallback(fn (string $slugId): ?ProductAdequacyCheck => $slugId === $this->check->slugId ? $this->check : null);

        $transactionManager = $this->createStub(TransactionManagerInterface::class);
        $transactionManager->method('transactional')->willReturnCallback(static fn (callable $cb): mixed => $cb());

        $userProvider = $this->createStub(CurrentUserProvider::class);
        $userProvider->method('getUser')->willReturn($this->cgp);

        return new ValidateAdequacyReportUseCase(
            new AdequacyReportDraftComposer($profileRepo, $checkRepo, $this->createStub(ClientFileRepositoryInterface::class), $documentRepo),
            $reportRepo,
            $this->createStub(ComplianceFolderRepositoryInterface::class),
            $transactionManager,
            $userProvider,
            $dispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }
}
