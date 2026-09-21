<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Suitability\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Suitability\Listener\AuditLogHighRiskLowCapacityWarningListener;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuditLogHighRiskLowCapacityWarningListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private AuditLogRepositoryInterface&MockObject $auditLogRepository;
    private ValidatedInvestorProfileRepositoryInterface&Stub $profileRepository;
    private AuditLogHighRiskLowCapacityWarningListener $listener;

    protected function setUp(): void
    {
        $this->auditLogRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->profileRepository = $this->createStub(ValidatedInvestorProfileRepositoryInterface::class);
        $this->listener = new AuditLogHighRiskLowCapacityWarningListener($this->auditLogRepository, $this->profileRepository);
    }

    private function profile(): ValidatedInvestorProfile
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $client = $this->createEntityState(Client::class, ['id' => Uuid::v7(), 'slugId' => 'cli_1', 'email' => 'a@b.fr', 'firstName' => 'A', 'lastName' => 'B']);
        $cgp = $this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']);
        $assessment = InvestorProfileAssessment::create($workspace, $client);

        return ValidatedInvestorProfile::validate(
            $workspace,
            $client,
            $assessment,
            $cgp,
            ['answers' => [], 'scoreSnapshot' => ['finalProfile' => 2]],
            version: 1,
        );
    }

    public function testWritesTheWarningAuditLogWhenTheMismatchIsFlagged(): void
    {
        $profile = $this->profile();
        $this->profileRepository->method('findBySlugId')->willReturn($profile);

        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::SUITABILITY_HIGH_RISK_LOW_CAPACITY_WARNING === $log->eventName
                && 'Marie Curie' === $log->payload['actor_name']
                && 'cgp' === $log->payload['actor_type']
                && $profile->slugId === $log->payload['profile_slug_id']));

        ($this->listener)(new InvestorProfileValidatedEvent(
            profileSlugId: $profile->slugId,
            clientSlugId: 'cli_1',
            version: 1,
            retainedProfileLevel: 2,
            overridden: false,
            validatedByName: 'Marie Curie',
            hasHighRiskLowCapacityMismatch: true,
        ));
    }

    public function testDoesNothingWhenTheMismatchIsNotFlagged(): void
    {
        $this->auditLogRepository->expects($this->never())->method('save');

        ($this->listener)(new InvestorProfileValidatedEvent(
            profileSlugId: 'vip_1',
            clientSlugId: 'cli_1',
            version: 1,
            retainedProfileLevel: 4,
            overridden: false,
            validatedByName: 'Marie Curie',
            hasHighRiskLowCapacityMismatch: false,
        ));
    }
}
