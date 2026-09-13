<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Listener\AuditLog;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Workspace\Event\WorkspaceReactivatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogWorkspaceReactivatedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(WorkspaceReactivatedEvent $event): void
    {
        $audit = AuditLog::initiate(
            eventName: AuditEventType::WORKSPACE_REACTIVATED,
            payload: [
                'reactivated_by_email' => $event->email,
                'actor_name' => $event->fullName,
                'actor_email' => $event->email,
            ],
            workspace: $event->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
