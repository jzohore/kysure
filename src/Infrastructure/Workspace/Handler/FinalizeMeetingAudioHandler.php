<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Handler;

use App\Application\Workspace\UseCase\AppendAudioChunkUseCase;
use App\Domain\Compliance\Event\MeetingAudioFinalizedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
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
        private MeetingRecordRepositoryInterface $recordRepository,
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

        $chunksDirectory = AppendAudioChunkUseCase::chunksDirectory($folder->slugId, $message->sessionId);

        // 🛡️ Idempotence : la MeetingRecording (unique par session + dossier) fait foi. Un rejeu
        // (retry Messenger, double « stop ») ne refait ni l'upload, ni le débit, ni l'analyse IA.
        if ($this->recordRepository->existsForSession($folder, $message->sessionId)) {
            $this->logger->info('Finalisation déjà effectuée, message ignoré.', ['slug' => $folder->slugId]);
            // Ménage d'un éventuel crash entre le commit de la trace et la suppression des chunks.
            $this->deleteChunks($this->storage->listFiles($chunksDirectory));

            return;
        }

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

            ['silent' => $isSilent, 'durationSeconds' => $measuredSeconds] = $this->analyzeAudio($finalPath);
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

            // Clé S3 déterministe : un rejeu écrase le même objet au lieu de créer un orphelin.
            $finalStoragePath = $this->storage->store(
                $fileToStore,
                sprintf('documents/meetings/%s', $folder->slugId),
                sprintf('%s.%s', $message->sessionId, $extension),
            );

            // 💰 Marge : le client déclare `consumed_seconds` ; la durée réellement mesurée sur le fichier
            // sert de plancher pour qu'une sous-déclaration ne contourne pas le quota.
            $consumedSeconds = max(0, $message->consumedSeconds, $measuredSeconds);

            // Débit du quota + statut ANALYZING + trace d'audit : faits dans UN SEUL flush par le
            // listener (LogMeetingRecordingUseCase), donc atomiques avec la clé d'idempotence.
            $this->eventDispatcher->dispatch(new MeetingAudioFinalizedEvent(
                folderSlugId: $folder->slugId,
                sessionId: $message->sessionId,
                s3Path: $finalStoragePath,
                consumedSeconds: $consumedSeconds
            ));

            // Suppression EN DERNIER : tant que la trace n'est pas commitée, un retry doit pouvoir réassembler.
            $this->deleteChunks($chunkKeys);
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

    /**
     * Une seule passe ffmpeg : détection de silence ET durée réelle du fichier.
     *
     * @return array{silent: bool, durationSeconds: int}
     */
    private function analyzeAudio(string $audioPath): array
    {
        $process = new Process(['ffmpeg', '-i', $audioPath, '-af', 'volumedetect', '-f', 'null', '/dev/null']);
        $process->setTimeout(60);
        $process->run();
        $output = $process->getErrorOutput();

        $silent = 1 === preg_match('/max_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/', $output, $volume)
            && (float) $volume[1] < -50.0;

        $durationSeconds = 0;
        if (1 === preg_match('/Duration:\s*(\d+):(\d{2}):(\d{2})(?:\.(\d+))?/', $output, $time)) {
            $durationSeconds = (int) $time[1] * 3600 + (int) $time[2] * 60 + (int) $time[3] + (isset($time[4]) ? 1 : 0);
        }

        return ['silent' => $silent, 'durationSeconds' => $durationSeconds];
    }

    /**
     * @param string[] $chunkKeys
     */
    private function deleteChunks(array $chunkKeys): void
    {
        foreach ($chunkKeys as $chunkKey) {
            $this->storage->delete($chunkKey);
        }
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
