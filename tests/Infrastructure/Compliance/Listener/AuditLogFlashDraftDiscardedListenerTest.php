<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Application\Compliance\UseCase\ComplianceFolder\DiscardFlashDraftUseCase;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\FolderCreationMethod;
use App\Domain\Compliance\Event\FlashDraftDiscardedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Listener\AuditLogFlashDraftDiscardedListener;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AuditLogFlashDraftDiscardedListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private AuditLogRepositoryInterface&MockObject $auditLogRepository;
    private ComplianceFolderRepositoryInterface&Stub $folderRepository;
    private AuditLogFlashDraftDiscardedListener $listener;

    protected function setUp(): void
    {
        $this->auditLogRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $this->listener = new AuditLogFlashDraftDiscardedListener($this->auditLogRepository, $this->folderRepository);
    }

    public function testWritesADeletionAuditLogWithTheSystemActor(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['slugId' => 'wrk_1', 'name' => 'Cabinet A']);
        $folder = $this->createEntityState(IndividualFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'KYC-FLASH01',
            'workspace' => $workspace,
            'creationMethod' => FolderCreationMethod::FLASH_QR_CODE->value,
        ]);
        $this->folderRepository->method('findOneBySlugId')->willReturn($folder);

        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_FOLDER_DELETED === $log->eventName
                && 'KYC-FLASH01' === $log->payload['folder_reference']
                && DiscardFlashDraftUseCase::SYSTEM_ACTOR === $log->payload['actor_name']
                && null === $log->payload['actor_email']
                && 'flash_qr_code' === $log->payload['creation_method']
                && $workspace === $log->workspace));

        ($this->listener)(new FlashDraftDiscardedEvent('comp_fol_1', DiscardFlashDraftUseCase::SYSTEM_ACTOR, 'Abandon.'));
    }

    public function testDoesNothingWhenTheFolderIsNotFound(): void
    {
        $this->folderRepository->method('findOneBySlugId')->willReturn(null);

        $this->auditLogRepository->expects($this->never())->method('save');

        ($this->listener)(new FlashDraftDiscardedEvent('comp_fol_unknown', DiscardFlashDraftUseCase::SYSTEM_ACTOR, 'Abandon.'));
    }
}
