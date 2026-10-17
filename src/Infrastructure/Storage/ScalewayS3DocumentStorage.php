<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Port\DocumentStorageInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

readonly class ScalewayS3DocumentStorage implements DocumentStorageInterface
{
    public function __construct(
        // Symfony va automatiquement injecter le disque S3 "kyc.storage"
        // configuré dans flysystem.yaml, ciblé explicitement par #[Target] (l'autowiring par nom est déprécié)
        #[Target('kycStorage')]
        private FilesystemOperator $kycStorage,
        private SluggerInterface $slugger,
        private LoggerInterface $logger,
    ) {
    }

    public function store(UploadedFile $file, string $directory, ?string $filename = null): string
    {
        // 🛡️ Si un nom de fichier est explicitement fourni (ex: chunks audio
        // "000012.chunk"), on l'utilise tel quel pour garder un ordre déterministe.
        // Sinon on garde le comportement existant (slug + uniqid).
        if (null !== $filename) {
            $fileName = $filename;
        } else {
            $originalFilename = pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $fileName = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
        }

        $targetPath = $directory . '/' . $fileName;

        try {
            $stream = fopen($file->getPathname(), 'r');
            $this->kycStorage->writeStream($targetPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (FilesystemException $e) {
            $this->logger->error('Impossible d\'écrire le fichier sur Scaleway S3.', [
                'path' => $targetPath,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur lors de la sauvegarde du document sur le cloud sécurisé.', $e->getCode(), $e);
        }

        return $targetPath;
    }

    public function delete(string $path): void
    {
        try {
            if ($this->kycStorage->fileExists($path)) {
                $this->kycStorage->delete($path);
                $this->logger->info('Document supprimé physiquement de Scaleway S3 : ' . $path);
            }
        } catch (FilesystemException $e) {
            $this->logger->error('Impossible de supprimer le document sur S3 : ' . $path, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getTemporaryUrl(string $path): string
    {
        return $this->kycStorage->temporaryUrl($path, new \DateTime('+20 minutes'));
    }

    public function listFiles(string $directory): array
    {
        try {
            return $this->kycStorage
                ->listContents($directory, false) // false = non récursif
                ->filter(static fn ($item) => $item->isFile())
                ->map(static fn ($item) => $item->path())
                ->toArray();
        } catch (FilesystemException $e) {
            $this->logger->error('Impossible de lister le dossier sur S3 : ' . $directory, [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur lors de la lecture du dossier sur le cloud sécurisé.', $e->getCode(), $e);
        }
    }

    public function getContents(string $path): string
    {
        try {
            return $this->kycStorage->read($path);
        } catch (FilesystemException $e) {
            $this->logger->error('Impossible de lire le fichier sur S3 : ' . $path, [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Erreur lors de la lecture du document sur le cloud sécurisé.', $e->getCode(), $e);
        }
    }
}
