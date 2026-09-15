<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Support\Event\SupportThreadAssignedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogSupportThreadAssignedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(SupportThreadAssignedEvent $event): void
    {
        $thread = $event->thread;

        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUPPORT_TICKET_ASSIGNED,
            payload: [
                'assigned_to_email' => $event->assignedTo?->email,
            ],
            workspace: $thread->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
