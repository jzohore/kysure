<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileRevokedEvent;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Un profil investisseur révoqué fragilise les rapports d'adéquation en vigueur qui le citent :
 * on ne les modifie ni ne les supprime (ce sont des pièces d'audit), on les SIGNALE. Le
 * signalement visible est dérivé de l'état du profil ({@see \App\Domain\AdequacyReport\Entity\AdequacyReport::isBasedOnRevokedProfile()}) ;
 * ce listener en laisse la trace datée, une entrée par rapport concerné.
 *
 * Synchrone et sans rejeu : l'event de révocation n'est émis qu'une fois (le profil ne peut
 * pas être révoqué deux fois).
 */
#[AsEventListener]
readonly class FlagAdequacyReportsOnProfileRevokedListener
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    public function __invoke(InvestorProfileRevokedEvent $event): void
    {
        $profile = $this->profileRepository->findBySlugId($event->profileSlugId);
        if (!$profile instanceof ValidatedInvestorProfile) {
            return;
        }

        foreach ($this->reportRepository->findInForceByInvestorProfile($profile) as $report) {
            $this->auditLogRepository->save(AuditLog::initiate(
                eventName: AuditEventType::ADEQUACY_REPORT_PROFILE_REVOKED,
                payload: [
                    'report_slug_id' => $report->slugId,
                    'folder_slug_id' => $report->complianceFolder->slugId,
                    'report_version' => $report->version,
                    'profile_slug_id' => $event->profileSlugId,
                    'profile_version' => $event->version,
                    'profile_revoke_reason' => $event->reason,
                    'actor_name' => $event->revokedByName,
                    'actor_type' => 'cgp',
                ],
                workspace: $report->workspace,
            ));
        }
    }
}
