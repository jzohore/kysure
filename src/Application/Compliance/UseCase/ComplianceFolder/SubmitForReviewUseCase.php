<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderSubmittedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Soumet le dossier pour analyse : il passe « en revue », ce qui ouvre la décision de
 * conformité au CGP. Le plus souvent déclenché par le client depuis son espace une fois toutes
 * les pièces déposées ; l'event alimente l'audit et prévient le cabinet.
 */
readonly class SubmitForReviewUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private CurrentUserProvider $currentUserProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ComplianceFolder $complianceFolder): void
    {
        // Gardes (statut, pièces obligatoires) portées par l'entité.
        $complianceFolder->submitForReview();
        $this->complianceFolderRepository->save($complianceFolder);

        // Contexte dual : un CGP connecté (User) ou le client depuis son espace (Client).
        $byCgp = $this->currentUserProvider->isAuthenticated();

        $this->eventDispatcher->dispatch(new ComplianceFolderSubmittedEvent(
            folderSlugId: $complianceFolder->slugId,
            actorType: $byCgp ? ComplianceFolderSubmittedEvent::ACTOR_CGP : ComplianceFolderSubmittedEvent::ACTOR_CLIENT,
            actorName: $byCgp ? $this->currentUserProvider->getUser()->getFullName() : null,
        ));
    }
}
