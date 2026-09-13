<?php

declare(strict_types=1);

namespace App\Infrastructure\Billing\Listener\Admin;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Billing\Event\AdminSubscriptionSeatsUpdatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogAdminSubscriptionSeatsUpdatedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(AdminSubscriptionSeatsUpdatedEvent $event): void
    {
        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUBSCRIPTION_SEATS_UPDATED,
            payload: [
                'previous_seats' => $event->previousSeats,
                'new_seats' => $event->newSeats,
                'actor_name' => $event->actorFullName,
                'actor_email' => $event->actorEmail,
            ],
            workspace: $event->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
