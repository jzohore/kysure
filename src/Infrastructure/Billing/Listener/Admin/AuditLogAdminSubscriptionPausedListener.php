<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Listener\Admin;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Billing\Event\AdminSubscriptionPausedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogAdminSubscriptionPausedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(AdminSubscriptionPausedEvent $event): void
    {
        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUBSCRIPTION_PAUSED,
            payload: [
                'actor_name' => $event->actorFullName,
                'actor_email' => $event->actorEmail,
                'source' => 'admin',
            ],
            workspace: $event->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
