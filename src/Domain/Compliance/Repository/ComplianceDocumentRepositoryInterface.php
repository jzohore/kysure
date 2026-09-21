<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Repository;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\User\Entity\Client;
use App\Domain\Workspace\Entity\Workspace;
use Symfony\Component\Uid\Uuid;

interface ComplianceDocumentRepositoryInterface
{
    /**
     * Récupère un document précis (pour le téléchargement ou la validation).
     */
    public function findById(Uuid|string $id): ?ComplianceDocument;

    /**
     * Récupère tous les documents ayant un statut spécifique (ex: 'PENDING' pour les agents qui vérifient).
     *
     * @return ComplianceDocument[]
     */
    public function findByStatus(string $status, Workspace $workspace): array;

    /**
     * Cherche si un document d'un certain type existe déjà pour un dossier (optimisation).
     */
    public function existsForFolder(Uuid|string $folderId, DocumentType $type): bool;

    public function save(ComplianceDocument $document, bool $flush = true): void;

    public function remove(ComplianceDocument $document, bool $flush = true): void;

    public function existsForFolderAndType(ComplianceFolder $folder, DocumentType $type): bool;

    public function findDerByFolder(ComplianceFolder $folder): ?ComplianceDocument;

    /**
     * Résout le DER ciblé par un lien d'accusé de réception à partir du SHA-256
     * du jeton (le clair n'est jamais stocké).
     */
    public function findOneByAcknowledgementTokenHash(string $tokenHash): ?ComplianceDocument;

    public function countPendingForClient(Client $client): int;

    /**
     * Comme {@see self::countPendingForClient()}, mais pour un seul dossier — nécessaire dès
     * qu'un client a plusieurs dossiers actifs à la fois (un par cabinet) : le compteur d'un
     * cabinet ne doit jamais inclure les pièces demandées par un autre.
     */
    public function countPendingForFolder(ComplianceFolder $folder): int;

    /**
     * @return array<ComplianceDocument>
     */
    public function findByFolder(ComplianceFolder $folder): array;
}
