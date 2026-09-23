<?php

declare(strict_types=1);

namespace App\Application\Workspace\UseCase;

use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Webmozart\Assert\Assert;

final readonly class AppendAudioChunkUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private DocumentStorageInterface $storage,
    ) {
    }

    /**
     * @throws \DomainException          si le quota (kill-switch ou minutes) est épuisé
     * @throws \InvalidArgumentException si le dossier n'existe pas
     */
    public function execute(string $folderSlugId, string $sessionId, UploadedFile $chunk, int $chunkIndex, ?string $mimeType = null): void
    {
        $folder = $this->folderRepository->findOneBySlugId($folderSlugId);
        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder) {
            throw new \InvalidArgumentException('Dossier introuvable.');
        }

        $folder->workspace->assertMeetingRecordingIsAllowed();

        // On mémorise le mimeType réel une seule fois (idempotent) : la
        // finalisation en a besoin pour savoir quelle extension/démuxeur
        // utiliser (webm vs mp4 vs ogg). Sans ça, un enregistrement Safari
        // (audio/mp4) est traité comme du WebM par erreur.
        if (null !== $mimeType && null === $folder->audioMimeType) {
            $folder->setAudioMimeType($mimeType);
            $this->folderRepository->save($folder);
        }

        // Chaque chunk part directement en S3, sous une clé indexée.
        // Aucune dépendance à un disque local partagé entre workers
        // FrankenPHP ou entre le process HTTP et un consumer Messenger séparé.
        $this->storage->store(
            $chunk,
            self::chunksDirectory($folder->slugId, $sessionId),
            sprintf('%06d.chunk', $chunkIndex)
        );
    }

    public static function chunksDirectory(string $folderSlugId, string $sessionId): string
    {
        Assert::regex($folderSlugId, '/^[A-Za-z0-9_-]+$/', 'Identifiant de dossier invalide.');
        Assert::uuid($sessionId, 'Format de session_id invalide.');

        // 🚀 Le chemin devient impénétrable aux Race Conditions
        return sprintf('tmp/meetings/%s/%s/chunks', $folderSlugId, $sessionId);
    }
}
