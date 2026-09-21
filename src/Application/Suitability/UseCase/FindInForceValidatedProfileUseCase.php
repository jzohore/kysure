<?php

declare(strict_types=1);

namespace App\Application\Suitability\UseCase;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;

/**
 * Le profil investisseur actuellement en vigueur pour un client **auprès du cabinet avec
 * lequel il est actuellement en relation** (non révoqué), ou `null` s'il n'en a aucun.
 *
 * Scopé par cabinet, jamais par client seul : un client peut être suivi par plusieurs cabinets
 * à la fois ({@see Client::$workspaces}), chacun avec son propre
 * historique de validation — mélanger les deux exposerait le travail d'un cabinet à un autre.
 * Le côté client n'a pas de notion de « cabinet courant » explicite : on la déduit du dossier
 * actif du client, sur le même principe que {@see GetOrCreateDraftAssessmentUseCase}.
 *
 * Existe aussi pour permettre aux contrôleurs de consulter cette information sans dépendre
 * directement du repository (frontières hexagonales, deptrac).
 */
readonly class FindInForceValidatedProfileUseCase
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $validatedProfileRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    /**
     * @param ?string $folderId le dossier depuis lequel le client est arrivé (carte cabinet du
     *                          tableau de bord) : un client peut avoir plusieurs cabinets actifs à la fois, ce
     *                          paramètre lève l'ambiguïté sur celui concerné. À défaut, retombe sur le dossier
     *                          actif le plus récent.
     */
    public function __invoke(Client $client, ?string $folderId = null): ?ValidatedInvestorProfile
    {
        $folder = null !== $folderId
            ? $this->folderRepository->findOneBySlugIdAndClient($folderId, $client)
            : $this->folderRepository->findActiveForClient($client);

        if (!$folder instanceof ComplianceFolder) {
            return null;
        }

        return $this->validatedProfileRepository->findInForceByClient($client, $folder->workspace);
    }
}
