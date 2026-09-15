<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\Entity\Admin;
use Pagerfanta\Pagerfanta;

interface AdminRepositoryInterface
{
    public function findByEmail(string $email): ?Admin;

    public function findById(string $id): ?Admin;

    public function findBySlugId(string $slugId): ?Admin;

    /**
     * Sauvegarde le client en base de données.
     */
    public function save(Admin $client, bool $flush = true): void;

    public function delete(Admin $admin): void;

    public function findByMagicLink(string $magicLink): ?Admin;

    /**
     * Liste paginée des comptes de l'équipe KYSURE.
     * $status ∈ {all, active, suspended, archived} (toute autre valeur = all).
     *
     * @return Pagerfanta<Admin>
     */
    public function paginateForList(int $page, int $perPage, ?string $search, string $status): Pagerfanta;

    /**
     * Nombre de comptes SUPER_ADMIN encore actifs (ni suspendus ni archivés).
     * Sert de garde-fou : on ne retire jamais le dernier administrateur.
     */
    public function countActiveSuperAdmins(): int;

    /**
     * Membres actifs de l'équipe KYSURE (ni suspendus ni archivés), pour les listes d'assignation.
     *
     * @return Admin[]
     */
    public function findAllActive(): array;
}
