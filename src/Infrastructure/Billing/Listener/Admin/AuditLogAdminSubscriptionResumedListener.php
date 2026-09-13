<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Listener\Admin;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Billing\Event\AdminSubscriptionResumedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogAdminSubscriptionResumedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(AdminSubscriptionResumedEvent $event): void
    {
        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUBSCRIPTION_RESUMED,
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
