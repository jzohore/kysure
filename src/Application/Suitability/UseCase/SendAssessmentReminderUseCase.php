<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Infrastructure\Suitability\Message\SendAssessmentReminderMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Relance par email les clients qui ont mis leur questionnaire profil investisseur en pause
 * alors qu'ils sont déjà bien avancés (§UX : le décrochage est plus coûteux à récupérer une
 * fois que le client a investi du temps sans finir). Idempotent : chaque assessment n'est
 * relancé qu'une seule fois (garde `reminderSentAt`), donc rejouable à volonté sans spammer.
 */
final readonly class SendAssessmentReminderUseCase
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): void
    {
        $threshold = $this->clock->now()->modify('-1 day');
        $stalledDrafts = $this->assessmentRepository->findStalledDraftsNeedingReminder($threshold);

        foreach ($stalledDrafts as $assessment) {
            if (!$assessment->isNearCompletion()) {
                continue;
            }

            $this->bus->dispatch(new SendAssessmentReminderMessage($assessment->slugId));

            $assessment->markReminderSent();
            $this->assessmentRepository->save($assessment);
        }
    }
}
