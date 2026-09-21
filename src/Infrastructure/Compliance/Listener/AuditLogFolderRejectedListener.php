<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderRejectedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogFolderRejectedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(ComplianceFolderRejectedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);

        if (!$folder instanceof ComplianceFolder) {
            return;
        }

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::KYC_FOLDER_REJECTED,
            payload: [
                'folder_slug_id' => $event->folderSlugId,
                'reason' => $event->reason,
                'actor_name' => $event->rejectedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $folder->workspace,
        ));
    }
}
