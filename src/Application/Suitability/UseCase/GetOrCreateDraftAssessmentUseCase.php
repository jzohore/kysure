<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Event\InvestorProfileAssessmentStartedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Reprend le brouillon en cours du client, ou en démarre un nouveau — prérempli depuis son
 * dernier questionnaire soumis à un autre cabinet, le cas échéant. Idempotent : rejouer cet
 * appel (rechargement de page, reprise après fermeture d'onglet) ne crée jamais de doublon.
 */
readonly class GetOrCreateDraftAssessmentUseCase
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private ValidatedInvestorProfileRepositoryInterface $validatedProfileRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param ?string $folderId le dossier depuis lequel le client est arrivé (carte cabinet du
     *                          tableau de bord) : un client peut avoir plusieurs cabinets actifs à la fois, ce
     *                          paramètre lève l'ambiguïté sur celui concerné. À défaut (lien historique sans ce
     *                          paramètre), retombe sur le dossier actif le plus récent.
     */
    public function __invoke(Client $client, ?string $folderId = null): InvestorProfileAssessment
    {
        $folder = null !== $folderId
            ? $this->folderRepository->findOneBySlugIdAndClient($folderId, $client)
            : $this->folderRepository->findActiveForClient($client);

        if (!$folder instanceof ComplianceFolder) {
            // Même invariant que GetClientDashboardUseCase : un client authentifié a toujours
            // un dossier actif, c'est de là que vient son espace de travail.
            throw new \LogicException(sprintf('Incohérence de domaine : aucun dossier actif pour le client %s.', $client->slugId));
        }
        $workspace = $folder->workspace;

        $existing = $this->assessmentRepository->findActiveDraftForClient($client, $workspace);
        if ($existing instanceof InvestorProfileAssessment) {
            return $existing;
        }

        if ($this->validatedProfileRepository->findInForceByClient($client, $workspace) instanceof ValidatedInvestorProfile) {
            // Le profil du client fait déjà foi auprès de ce cabinet (validé par le CGP) : pas
            // de nouveau questionnaire tant que ce profil n'a pas été révoqué. Sinon le client
            // pourrait se re-profiler lui-même sans passer par la revue conseiller. Un autre
            // cabinet qui suit aussi ce client garde son propre historique, indépendant.
            throw new \DomainException(sprintf('Le client %s a déjà un profil investisseur validé en vigueur auprès de ce cabinet.', $client->slugId));
        }

        $pendingSubmission = $this->assessmentRepository->findLatestSubmittedForClient($client, $workspace);
        if ($pendingSubmission instanceof InvestorProfileAssessment) {
            // Déjà soumis, en attente d'un examen du CGP : on ne recrée pas de brouillon tant
            // que ce dernier n'a rien validé (sans quoi une simple visite de la page créerait
            // un second assessment concurrent du premier, jamais examiné).
            return $pendingSubmission;
        }

        $assessment = InvestorProfileAssessment::create($workspace, $client);

        // Préremplissage de convenance depuis un autre cabinet, le cas échéant : le client
        // confirme/ajuste plutôt que retaper le questionnaire depuis zéro. Chaque cabinet
        // garde sa propre validation, indépendante — voir InvestorProfileAssessment::prefillFrom().
        $priorSubmission = $this->assessmentRepository->findMostRecentSubmittedAcrossWorkspaces($client);
        if ($priorSubmission instanceof InvestorProfileAssessment) {
            $assessment->prefillFrom($priorSubmission);
        }

        $this->assessmentRepository->save($assessment);

        $this->eventDispatcher->dispatch(new InvestorProfileAssessmentStartedEvent($assessment->slugId));

        return $assessment;
    }
}
