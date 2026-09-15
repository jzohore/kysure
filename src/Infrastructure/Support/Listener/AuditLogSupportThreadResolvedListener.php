<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Support\Event\SupportThreadResolvedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly class AuditLogSupportThreadResolvedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(SupportThreadResolvedEvent $event): void
    {
        $thread = $event->thread;

        $audit = AuditLog::initiate(
            eventName: AuditEventType::SUPPORT_TICKET_RESOLVED,
            payload: [
                'category' => $thread->category,
                'topic' => $thread->topic,
            ],
            workspace: $thread->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
