<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderApprovedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogFolderApprovedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(ComplianceFolderApprovedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);

        if (!$folder instanceof ComplianceFolder) {
            return;
        }

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::KYC_FOLDER_APPROVED,
            payload: [
                'folder_slug_id' => $event->folderSlugId,
                'risk_level' => $event->riskLevel,
                'actor_name' => $event->approvedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $folder->workspace,
        ));
    }
}
