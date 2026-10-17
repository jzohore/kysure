<?php

declare(strict_types=1);

namespace App\Domain\Screening\Repository;

use App\Domain\Screening\Entity\ScreeningAudit;
use App\Domain\Workspace\Entity\Workspace;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Uid\Uuid;

interface ScreeningAuditRepositoryInterface
{
    public function save(ScreeningAudit $audit): void;

    /**
     * Persiste un lot d'audits en UN SEUL flush puis les détache de l'identity map (traitements
     * de masse : la mémoire reste constante). `detach` plutôt que `clear` : les autres entités
     * gérées par le contexte appelant (ex. journal d'exécution du cron) ne sont pas touchées.
     *
     * @param list<ScreeningAudit> $audits
     */
    public function saveBatchAndDetach(array $audits): void;

    public function findRecentIdenticalSearch(Workspace $workspace, string $query, int $hoursLimit): ?ScreeningAudit;

    public function findOneBySlug(string $id): ?ScreeningAudit;

    public function getById(Uuid|string $id): ScreeningAudit;

    /**
     * @return Pagerfanta<ScreeningAudit>
     */
    public function getScreeningList(string $workspaceSlugId, ?string $search = null): Pagerfanta;

    public function countSearchesSince(Workspace $workspace, \DateTimeImmutable $since): int;

    public function countAll(): int;

    /**
     * Derniers criblages d'un espace, pour l'aperçu du tableau de bord.
     *
     * @return ScreeningAudit[]
     */
    public function findRecentByWorkspace(Workspace $workspace, int $limit = 5): array;

    /**
     * Criblages encore en traitement (en attente ou génération du PDF en cours).
     */
    public function countInProgressForWorkspace(Workspace $workspace): int;

    /**
     * Audits dont les résultats bruts sont minimisables : créés avant
     * `$createdBefore`, avec au moins une correspondance, pas encore minimisés.
     *
     * @return list<ScreeningAudit>
     */
    public function findMinimizableResults(\DateTimeImmutable $createdBefore, int $limit): array;
}
