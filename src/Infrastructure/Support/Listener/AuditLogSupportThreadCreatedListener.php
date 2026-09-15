<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Support\Event\SupportThreadCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogSupportThreadCreatedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(SupportThreadCreatedEvent $event): void
    {
        $thread = $event->thread;

        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUPPORT_TICKET_CREATED,
            payload: [
                'category' => $thread->category,
                'topic' => $thread->topic,
                'actor_email' => $thread->user->email,
            ],
            workspace: $thread->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
