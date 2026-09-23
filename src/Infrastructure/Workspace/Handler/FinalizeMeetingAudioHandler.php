<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Handler;

use App\Application\Workspace\UseCase\AppendAudioChunkUseCase;
use App\Domain\Compliance\Enum\MeetingProcessingStatus;
use App\Domain\Compliance\Event\MeetingAudioFinalizedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use App\Infrastructure\Compliance\Message\FinalizeMeetingAudioMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final readonly class FinalizeMeetingAudioHandler
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private WorkspaceRepositoryInterface $workspaceRepository,
        private DocumentStorageInterface $storage,
        private LoggerInterface $logger,
        private Filesystem $filesystem,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(FinalizeMeetingAudioMessage $message): void
    {
        $folder = $this->folderRepository->findOneBySlugId($message->folderSlugId);
        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder) {
            $this->logger->error('Finalisation avortée : Dossier introuvable.', ['slug' => $message->folderSlugId]);

            return;
        }

        // 🚀 FIX 1 : Suppression de la condition d'idempotence sur le statut du dossier.
        // On permet de finaliser autant de sessions que l'on veut sur un même dossier.

        $chunksDirectory = AppendAudioChunkUseCase::chunksDirectory($folder->slugId, $message->sessionId);
        $chunkKeys = $this->storage->listFiles($chunksDirectory);

        if ([] === $chunkKeys) {
            $this->logger->error('Finalisation avortée : aucun chunk trouvé.', ['slug' => $folder->slugId]);
            throw new \RuntimeException('Aucun flux audio à analyser.');
        }

        sort($chunkKeys, \SORT_STRING);

        $mimeType = $folder->audioMimeType;
        $extension = $this->extensionForMimeType($mimeType);

        $workDir = sys_get_temp_dir() . '/meeting_remux_' . $folder->slugId . '_' . uniqid();
        $this->filesystem->mkdir($workDir);

        try {
            // 🚀 FIX 2 : On assemble les bytes nativement en PHP, sans FFmpeg
            $localRawPath = $this->assembleChunksLocally($chunkKeys, $workDir, $extension);

            // On passe le fichier entier (raw) à FFmpeg pour le nettoyer et le compresser
            $finalPath = $this->remuxWithFfmpeg($localRawPath, $workDir, $extension);

            $isSilent = $this->detectSilence($finalPath);
            if ($isSilent) {
                $this->logger->warning('Audio quasi-silencieux détecté à la finalisation.', ['slug' => $folder->slugId]);
            }

            $fileToStore = new UploadedFile(
                path: $finalPath,
                originalName: sprintf(
                    'entretien_vocal_%s_%s.%s',
                    str_replace(' ', '_', $folder->reference),
                    substr($message->sessionId, 0, 8),
                    $extension
                ),
                mimeType: $mimeType ?? 'audio/webm',
                test: true
            );

            $finalDirectory = sprintf('documents/meetings/%s', $folder->slugId);
            $finalStoragePath = $this->storage->store($fileToStore, $finalDirectory);

            foreach ($chunkKeys as $chunkKey) {
                $this->storage->delete($chunkKey);
            }

            $folder->workspace->consumeMeetingSeconds($message->consumedSeconds);
            $this->workspaceRepository->save($folder->workspace);

            $folder->setMeetingProcessingStatus(MeetingProcessingStatus::ANALYZING);
            $this->folderRepository->save($folder);

            $this->eventDispatcher->dispatch(new MeetingAudioFinalizedEvent(
                folderSlugId: $folder->slugId,
                sessionId: $message->sessionId,
                s3Path: $finalStoragePath,
                consumedSeconds: $message->consumedSeconds
            ));
        } finally {
            $this->filesystem->remove($workDir);
        }
    }

    /**
     * Assemble les chunks audio téléchargés depuis S3 en un seul fichier binaire local.
     *
     * @param string[] $chunkKeys Liste des chemins (clés S3) des fragments audio
     */
    private function assembleChunksLocally(array $chunkKeys, string $workDir, string $extension): string
    {
        $rawPath = sprintf('%s/raw.%s', $workDir, $extension);

        // Mode 'wb' (Write Binary) obligatoire pour la manipulation de flux média
        $handle = fopen($rawPath, 'w');

        // 🛡️ Guard clause : On sécurise le type de $handle (resource) pour PHPStan et l'OS
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Impossible d\'ouvrir le fichier temporaire en écriture : %s', $rawPath));
        }

        try {
            foreach ($chunkKeys as $chunkKey) {
                // Écriture des bytes bruts de S3 dans notre fichier unique
                $content = $this->storage->getContents($chunkKey);

                if (false === fwrite($handle, $content)) {
                    throw new \RuntimeException(sprintf('Échec de l\'écriture du chunk "%s" dans le fichier final.', $chunkKey));
                }
            }
        } finally {
            // 🛡️ On s'assure de libérer la mémoire (I/O lock) quoi qu'il arrive
            fclose($handle);
        }

        return $rawPath;
    }

    private function remuxWithFfmpeg(string $rawPath, string $workDir, string $extension): string
    {
        $outputPath = $workDir . '/final.' . $extension;

        $codec = match ($extension) {
            'webm' => 'libopus',
            'mp4' => 'aac',
            'ogg' => 'libvorbis',
            default => 'libopus',
        };

        $process = new Process([
            'ffmpeg',
            '-y',
            '-i', $rawPath, // 🚀 On donne le fichier brut assemblé
            '-c:a', $codec,
            '-b:a', '48k',
            '-ac', '1',
            $outputPath,
        ]);

        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Erreur FFmpeg : ' . $process->getErrorOutput());
        }

        return $outputPath;
    }

    private function detectSilence(string $audioPath): bool
    {
        $process = new Process(['ffmpeg', '-i', $audioPath, '-af', 'volumedetect', '-f', 'null', '/dev/null']);
        $process->setTimeout(60);
        $process->run();
        $output = $process->getErrorOutput();

        if (!preg_match('/max_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $output, $matches)) {
            return false;
        }

        return (float) $matches[1] < -50.0;
    }

    private function extensionForMimeType(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mp4' => 'mp4',
            'audio/ogg' => 'ogg',
            default => 'webm',
        };
    }
}
