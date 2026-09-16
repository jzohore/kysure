<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Suitability\Event\InvestorProfileAssessmentStartedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

#[AsEventListener]
readonly class AuditLogAssessmentStartedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
    ) {
    }

    public function __invoke(InvestorProfileAssessmentStartedEvent $event): void
    {
        $assessment = $this->assessmentRepository->findOneBySlugId($event->assessmentSlugId);
        Assert::notNull($assessment, 'Assessment profil investisseur introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::SUITABILITY_ASSESSMENT_STARTED,
            payload: [
                'assessment_slug_id' => $event->assessmentSlugId,
                'client_slug_id' => $assessment->client->slugId,
                'actor_type' => 'client',
            ],
            workspace: $assessment->workspace,
        ));
    }
}
