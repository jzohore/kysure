<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Repository;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

interface ComplianceFolderRepositoryInterface
{
    /**
     * Sauvegarde ou met à jour un dossier.
     */
    public function save(ComplianceFolder $folder, bool $flush = true): void;

    /**
     * Supprime un dossier.
     */
    public function remove(ComplianceFolder $folder, bool $flush = true): void;

    /**
     * Récupère un dossier par son identifiant unique.
     */
    public function findById(Uuid|string $id): ComplianceFolder;

    /**
     * Récupère un dossier par sa référence interne (ex: KYC-123456).
     */
    public function findByReference(string $reference): ComplianceFolder;

    /**
     * Liste tous les dossiers actifs d'un espace de travail.
     *
     * @return ComplianceFolder[]
     */
    public function findAllActiveByWorkspace(Workspace $workspace): array;

    public function countDraftsForWorkspace(Workspace $workspace): int;

    /**
     * Dossiers « en cours » : ni brouillon, ni supprimé, ni archivé, ni rejeté.
     */
    public function countActiveForWorkspace(Workspace $workspace): int;

    /**
     * Compte les dossiers d'un espace dans un ou plusieurs statuts donnés (ex: tableau de bord conseiller).
     *
     * @param list<ComplianceFolderStatus> $statuses
     */
    public function countByStatusesForWorkspace(Workspace $workspace, array $statuses): int;

    /**
     * Tous les dossiers non supprimés de l'espace (sert au « a déjà créé un dossier »).
     */
    public function countForWorkspace(Workspace $workspace): int;

    /**
     * @return Pagerfanta<ComplianceFolder>
     */
    public function findAllByWorkspace(Workspace $workspace, ?string $search = null, ?ComplianceFolderStatus $status = null): Pagerfanta;

    /**
     * Derniers dossiers créés dans l'espace (aperçu tableau de bord).
     *
     * @return list<ComplianceFolder>
     */
    public function findRecentByWorkspace(Workspace $workspace, int $limit = 5): array;

    /**
     * Liste paginée tous cabinets, pour le back-office KYSURE.
     *
     * @return Pagerfanta<ComplianceFolder>
     */
    public function findAllForAdmin(int $page, int $perPage, ?string $search = null, ?ComplianceFolderStatus $status = null): Pagerfanta;

    public function findOneLastDraftIndividuals(string $method, Workspace $workspace): ?ComplianceFolder;

    public function findActiveForClient(Client $client): ?ComplianceFolder;

    /**
     * Récupère et transforme la liste des dossiers actifs pour le portail client.
     *
     * @return list<ComplianceFolder>
     */
    public function findAllActiveForClient(Client $client): array;

    public function findOneBySlugIdAndClient(string $folderId, Client $client): ?ComplianceFolder;

    public function findOneBySlugId(string $slugId): ?ComplianceFolder;
}
