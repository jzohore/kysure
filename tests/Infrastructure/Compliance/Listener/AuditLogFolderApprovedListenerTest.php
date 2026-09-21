<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Listener\AuditLogFolderApprovedListener;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AuditLogFolderApprovedListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private AuditLogRepositoryInterface&MockObject $auditLogRepository;
    private ComplianceFolderRepositoryInterface&Stub $folderRepository;
    private AuditLogFolderApprovedListener $listener;

    protected function setUp(): void
    {
        $this->auditLogRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $this->listener = new AuditLogFolderApprovedListener($this->auditLogRepository, $this->folderRepository);
    }

    public function testWritesTheApprovalAuditLog(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $folder = $this->createEntityState(IndividualFolder::class, ['slugId' => 'comp_fol_1', 'workspace' => $workspace]);
        $this->folderRepository->method('findOneBySlugId')->willReturn($folder);

        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_FOLDER_APPROVED === $log->eventName
                && 'HIGH' === $log->payload['risk_level']
                && 'Marie CURIE' === $log->payload['actor_name']
                && 'cgp' === $log->payload['actor_type']));

        ($this->listener)(new ComplianceFolderApprovedEvent('comp_fol_1', 'HIGH', 'Marie CURIE'));
    }

    public function testDoesNothingWhenTheFolderIsNotFound(): void
    {
        $this->folderRepository->method('findOneBySlugId')->willReturn(null);

        $this->auditLogRepository->expects($this->never())->method('save');

        ($this->listener)(new ComplianceFolderApprovedEvent('comp_fol_unknown', 'LOW', 'Marie CURIE'));
    }
}
