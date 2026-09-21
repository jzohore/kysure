<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Event\InvestorProfileRevokedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

#[AsEventListener]
readonly class AuditLogProfileRevokedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
    ) {
    }

    public function __invoke(InvestorProfileRevokedEvent $event): void
    {
        $profile = $this->profileRepository->findBySlugId($event->profileSlugId);
        Assert::notNull($profile, 'Profil investisseur introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::SUITABILITY_PROFILE_REVOKED,
            payload: [
                'profile_slug_id' => $event->profileSlugId,
                'client_slug_id' => $event->clientSlugId,
                'version' => $event->version,
                'reason' => $event->reason,
                'actor_name' => $event->revokedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $profile->workspace,
        ));
    }
}
