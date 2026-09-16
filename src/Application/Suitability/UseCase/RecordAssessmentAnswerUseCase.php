<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Enum\AnswerSource;
use App\Domain\Suitability\Enum\QuestionKey;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;

/**
 * Enregistre la réponse du client à une question, sauvegarde immédiate (permet la reprise de
 * session : rien n'est perdu si le client ferme l'onglet en cours de route).
 */
readonly class RecordAssessmentAnswerUseCase
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
    ) {
    }

    public function __invoke(InvestorProfileAssessment $assessment, QuestionKey $key, mixed $value, Client $answeredBy): void
    {
        $assessment->recordAnswer($key, $value, AnswerSource::CLIENT, $answeredBy->id);
        $this->assessmentRepository->save($assessment);
    }
}
