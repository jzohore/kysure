<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Event\InvestorProfileValidatedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Mise en garde écrite tracée (décision lot 5) : quand un profil validé cumule une appétence
 * au risque très forte et une capacité à subir des pertes très faible (« trader sans filet »),
 * consigne un événement d'audit dédié en plus du plafonnement déjà appliqué au score — le CGP
 * reste seul décisionnaire, mais la mise en garde figure désormais dans la piste d'audit du
 * cabinet, consultable à tout moment (pas de blocage de la validation elle-même).
 */
#[AsEventListener]
readonly class AuditLogHighRiskLowCapacityWarningListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
    ) {
    }

    public function __invoke(InvestorProfileValidatedEvent $event): void
    {
        if (!$event->hasHighRiskLowCapacityMismatch) {
            return;
        }

        $profile = $this->profileRepository->findBySlugId($event->profileSlugId);
        Assert::notNull($profile, 'Profil investisseur introuvable pour la mise en garde tracée.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::SUITABILITY_HIGH_RISK_LOW_CAPACITY_WARNING,
            payload: [
                'profile_slug_id' => $event->profileSlugId,
                'client_slug_id' => $event->clientSlugId,
                'version' => $event->version,
                'retained_profile_level' => $event->retainedProfileLevel,
                'message' => 'Appétence au risque déclarée très forte malgré une capacité à subir des pertes très faible : le profil final a été plafonné en conséquence.',
                'actor_name' => $event->validatedByName,
                'actor_type' => 'cgp',
            ],
            workspace: $profile->workspace,
        ));
    }
}
