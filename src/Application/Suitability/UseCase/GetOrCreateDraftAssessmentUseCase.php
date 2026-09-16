<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Event\InvestorProfileAssessmentStartedEvent;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\User\Entity\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Reprend le brouillon en cours du client, ou en démarre un nouveau. Idempotent : rejouer cet
 * appel (rechargement de page, reprise après fermeture d'onglet) ne crée jamais de doublon.
 */
readonly class GetOrCreateDraftAssessmentUseCase
{
    public function __construct(
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(Client $client): InvestorProfileAssessment
    {
        $existing = $this->assessmentRepository->findActiveDraftForClient($client);
        if ($existing instanceof InvestorProfileAssessment) {
            return $existing;
        }

        $folder = $this->folderRepository->findActiveForClient($client);
        if (!$folder instanceof ComplianceFolder) {
            // Même invariant que GetClientDashboardUseCase : un client authentifié a toujours
            // un dossier actif, c'est de là que vient son espace de travail.
            throw new \LogicException(sprintf('Incohérence de domaine : aucun dossier actif pour le client %s.', $client->slugId));
        }

        $assessment = InvestorProfileAssessment::create($folder->workspace, $client);
        $this->assessmentRepository->save($assessment);

        $this->eventDispatcher->dispatch(new InvestorProfileAssessmentStartedEvent($assessment->slugId));

        return $assessment;
    }
}
