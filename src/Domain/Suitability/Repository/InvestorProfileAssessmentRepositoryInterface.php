<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Repository;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\User\Entity\Client;

interface InvestorProfileAssessmentRepositoryInterface
{
    public function save(InvestorProfileAssessment $assessment, bool $flush = true): void;

    public function findOneBySlugId(string $slugId): ?InvestorProfileAssessment;

    /**
     * Le brouillon en cours d'un client, s'il en existe un (un seul brouillon actif à la fois).
     */
    public function findActiveDraftForClient(Client $client): ?InvestorProfileAssessment;

    /**
     * Le dernier questionnaire soumis par ce client (validé ou non), pour l'écran de revue
     * conseiller. `null` si le client n'a jamais soumis de questionnaire.
     */
    public function findLatestSubmittedForClient(Client $client): ?InvestorProfileAssessment;
}
