<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Event\InvestorProfileRevokedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Infrastructure\AdequacyReport\Listener\FlagAdequacyReportsOnProfileRevokedListener;
use App\Tests\Domain\AdequacyReport\AdequacyReportFixturesTrait;
use PHPUnit\Framework\TestCase;

final class FlagAdequacyReportsOnProfileRevokedListenerTest extends TestCase
{
    use AdequacyReportFixturesTrait;

    protected function setUp(): void
    {
        $this->setUpAdequacyFixtures();
    }

    public function testEachReportInForceOnTheRevokedProfileIsFlaggedInTheAuditTrailWithoutBeingTouched(): void
    {
        $report = $this->validateReport();
        $contentHash = $report->contentHash;

        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findBySlugId')->willReturn($this->profile);

        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->expects(self::once())->method('findInForceByInvestorProfile')->with($this->profile)->willReturn([$report]);
        $reportRepo->expects(self::never())->method('save');

        $auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::once())->method('save')->with(self::callback(static function (AuditLog $log) use ($report): bool {
            self::assertSame(AuditEventType::ADEQUACY_REPORT_PROFILE_REVOKED, $log->eventName);
            self::assertSame($report->slugId, $log->payload['report_slug_id']);
            self::assertSame('Nouveau questionnaire du client.', $log->payload['profile_revoke_reason']);

            return true;
        }));

        new FlagAdequacyReportsOnProfileRevokedListener($profileRepo, $reportRepo, $auditRepo)(new InvestorProfileRevokedEvent(
            profileSlugId: $this->profile->slugId,
            clientSlugId: $this->client->slugId,
            version: 1,
            reason: 'Nouveau questionnaire du client.',
            revokedByName: 'Marie CURIE',
        ));

        self::assertTrue($report->isInForce(), 'Signalé, jamais révoqué automatiquement.');
        self::assertSame($contentHash, $report->contentHash);
    }

    public function testUnknownProfileIsIgnored(): void
    {
        $profileRepo = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $profileRepo->method('findBySlugId')->willReturn(null);

        $reportRepo = $this->createMock(AdequacyReportRepositoryInterface::class);
        $reportRepo->expects(self::never())->method('findInForceByInvestorProfile');
        $auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::never())->method('save');

        new FlagAdequacyReportsOnProfileRevokedListener($profileRepo, $reportRepo, $auditRepo)(new InvestorProfileRevokedEvent('vip_x', 'cli_1', 1, 'Motif', 'Marie CURIE'));
    }
}
