<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Repository;

use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Symfony\Component\Uid\Uuid;

interface ValidatedInvestorProfileRepositoryInterface
{
    /**
     * Volontairement pas de `remove()` : un profil validé est une pièce d'audit, il se
     * révoque, il ne se supprime pas.
     */
    public function save(ValidatedInvestorProfile $profile, bool $flush = true): void;

    public function findById(Uuid|string $id): ?ValidatedInvestorProfile;

    public function findBySlugId(string $slugId): ?ValidatedInvestorProfile;

    /**
     * Même lecture que {@see self::findBySlugId()}, mais scopée au cabinet courant : un CGP ne
     * doit jamais pouvoir accéder (même en lecture) au profil validé d'un client par un cabinet
     * concurrent en devinant son slug.
     */
    public function findBySlugIdAndWorkspace(string $slugId, Workspace $workspace): ?ValidatedInvestorProfile;

    /**
     * La version actuellement en vigueur pour ce client **auprès de ce cabinet**, non révoquée,
     * ou `null` si aucun profil n'y est validé. Scopé par cabinet : un client peut être suivi
     * par plusieurs cabinets à la fois ({@see Client::$workspaces}),
     * chacun avec son propre historique de validation — jamais mélangés entre eux.
     */
    public function findInForceByClient(Client $client, Workspace $workspace): ?ValidatedInvestorProfile;

    /**
     * Le plus grand numéro de version émis pour ce client **auprès de ce cabinet** (0 si aucun).
     * La prochaine validation dans ce cabinet utilisera `+ 1`.
     */
    public function findLatestVersionNumber(Client $client, Workspace $workspace): int;

    /**
     * Historique complet des profils validés du client **auprès de ce cabinet**, du plus
     * récent au plus ancien.
     *
     * @return list<ValidatedInvestorProfile>
     */
    public function findAllByClient(Client $client, Workspace $workspace): array;

    /**
     * Nombre de questionnaires soumis n'ayant pas encore de profil validé en vigueur, pour
     * le tableau de bord conseiller.
     */
    public function countPendingValidationForWorkspace(Workspace $workspace): int;
}
