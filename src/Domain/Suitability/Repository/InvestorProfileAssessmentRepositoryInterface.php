<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Repository;

use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;

interface InvestorProfileAssessmentRepositoryInterface
{
    public function save(InvestorProfileAssessment $assessment, bool $flush = true): void;

    public function findOneBySlugId(string $slugId): ?InvestorProfileAssessment;

    /**
     * Le brouillon en cours d'un client **auprès de ce cabinet**, s'il en existe un (un seul
     * brouillon actif à la fois, par cabinet). Scopé par cabinet pour la même raison que
     * {@see self::findLatestSubmittedForClient()}.
     */
    public function findActiveDraftForClient(Client $client, Workspace $workspace): ?InvestorProfileAssessment;

    /**
     * Le dernier questionnaire soumis par ce client **auprès de ce cabinet** (validé ou non),
     * pour l'écran de revue conseiller. `null` si le client n'a jamais rien soumis à ce
     * cabinet. Scopé par cabinet : un client peut être suivi par plusieurs cabinets à la fois
     * ({@see Client::$workspaces}) — chacun ne doit voir que ses
     * propres questionnaires, jamais ceux soumis à un autre cabinet.
     */
    public function findLatestSubmittedForClient(Client $client, Workspace $workspace): ?InvestorProfileAssessment;

    /**
     * Brouillons inactifs depuis au moins `$before`, jamais encore relancés par email — pour
     * la tâche planifiée de relance ({@see \App\Application\Suitability\UseCase\SendAssessmentReminderUseCase}).
     *
     * @return list<InvestorProfileAssessment>
     */
    public function findStalledDraftsNeedingReminder(\DateTimeInterface $before): array;

    /**
     * Le dernier questionnaire soumis par ce client, **tous cabinets confondus**. Seule
     * lecture volontairement non scopée par cabinet dans tout le domaine Suitability : sert
     * uniquement à préremplir un nouveau questionnaire par convenance pour le client
     * ({@see InvestorProfileAssessment::prefillFrom()}), jamais
     * à transférer une validation ou une information qui engagerait un cabinet à la place
     * d'un autre.
     */
    public function findMostRecentSubmittedAcrossWorkspaces(Client $client): ?InvestorProfileAssessment;
}
