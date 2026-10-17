<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Repository;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\MeetingRecording;
use Symfony\Component\Uid\Uuid;

interface MeetingRecordRepositoryInterface
{
    /**
     * Sauvegarde ou met à jour un dossier.
     */
    public function save(MeetingRecording $meetingRecording, bool $flush = true): void;

    /**
     * Supprime un dossier.
     */
    public function remove(MeetingRecording $meetingRecording, bool $flush = true): void;

    /**
     * Récupère un dossier par son identifiant unique.
     */
    public function findById(Uuid|string $id): ?MeetingRecording;

    public function findBySlugId(string $slugId): ?MeetingRecording;

    /**
     * Clé d'idempotence de la finalisation audio (contrainte unique session + dossier).
     */
    public function existsForSession(ComplianceFolder $complianceFolder, string $sessionId): bool;

    /**
     * Liste tous les dossiers actifs d'un espace de travail.
     *
     * @return MeetingRecording[]
     */
    public function findAllByFolder(ComplianceFolder $complianceFolder): array;

    /**
     * Liste tous les dossiers actifs d'un espace de travail.
     *
     * @return MeetingRecording[]
     */
    public function findActiveByFolder(ComplianceFolder $complianceFolder): array;
}
