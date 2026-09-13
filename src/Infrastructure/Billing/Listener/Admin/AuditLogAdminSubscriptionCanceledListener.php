<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Listener\Admin;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Billing\Event\AdminSubscriptionCanceledEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogAdminSubscriptionCanceledListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(AdminSubscriptionCanceledEvent $event): void
    {
        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUBSCRIPTION_CANCELED,
            payload: [
                'reason' => $event->reason,
                'actor_name' => $event->actorFullName,
                'actor_email' => $event->actorEmail,
                'source' => 'admin',
            ],
            workspace: $event->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
