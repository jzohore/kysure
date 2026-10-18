<?php

declare(strict_types=1);

namespace App\Infrastructure\Firm\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Firm\Event\RegulatoryProfileDeferredEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Journalise le report du profil réglementaire : le cabinet a été informé que la génération
 * du DER reste indisponible tant que ce profil n'est pas complet.
 */
#[AsEventListener(event: RegulatoryProfileDeferredEvent::class)]
readonly class AuditLogRegulatoryProfileDeferredListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(RegulatoryProfileDeferredEvent $event): void
    {
        Assert::notNull($event->user->id);
        Assert::notNull($event->workspace->name);

        $audit = AuditLog::initiate(
            eventName: AuditEventType::ONBOARDING_REGULATORY_PROFILE_DEFERRED,
            payload: [
                'actor_name' => $event->user->getFullName(),
                'actor_email' => $event->user->email,
                'workspace_name' => $event->workspace->name,
            ],
            workspace: $event->workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
