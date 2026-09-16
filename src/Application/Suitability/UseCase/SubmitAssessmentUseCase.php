<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Event\InvestorProfileAssessmentSubmittedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Service\AssessmentAnswersAssembler;
use App\Domain\Suitability\Service\SuitabilityScoringEngineInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Calcule le profil investisseur à partir des réponses et fige l'assessment. Idempotent :
 * rejouer cet appel sur un assessment déjà soumis ne recalcule pas le score déjà enregistré
 * (double soumission réseau, double clic).
 */
readonly class SubmitAssessmentUseCase
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private AssessmentAnswersAssembler $assembler,
        private SuitabilityScoringEngineInterface $scoringEngine,
        private TransactionManagerInterface $transactionManager,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(InvestorProfileAssessment $assessment): void
    {
        if ($assessment->isSubmitted()) {
            return;
        }

        $input = $this->assembler->assemble($assessment->answersAsMap());
        $result = $this->scoringEngine->score($input);

        $assessment->submit($result->toArray());

        $this->transactionManager->transactional(function () use ($assessment): void {
            $this->assessmentRepository->save($assessment);
        });

        $this->eventDispatcher->dispatch(new InvestorProfileAssessmentSubmittedEvent(
            assessmentSlugId: $assessment->slugId,
            finalProfileLevel: $result->finalProfile->value,
        ));
    }
}
