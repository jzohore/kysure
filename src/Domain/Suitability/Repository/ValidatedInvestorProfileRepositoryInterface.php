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
     * La version actuellement en vigueur pour ce client (non révoquée), ou `null` si le
     * client n'a aucun profil validé.
     */
    public function findInForceByClient(Client $client): ?ValidatedInvestorProfile;

    /**
     * Le plus grand numéro de version émis pour ce client (0 si aucun). La prochaine
     * validation utilisera `+ 1`.
     */
    public function findLatestVersionNumber(Client $client): int;

    /**
     * Historique complet des profils validés du client, du plus récent au plus ancien.
     *
     * @return list<ValidatedInvestorProfile>
     */
    public function findAllByClient(Client $client): array;

    /**
     * Nombre de questionnaires soumis n'ayant pas encore de profil validé en vigueur, pour
     * le tableau de bord conseiller.
     */
    public function countPendingValidationForWorkspace(Workspace $workspace): int;
}
