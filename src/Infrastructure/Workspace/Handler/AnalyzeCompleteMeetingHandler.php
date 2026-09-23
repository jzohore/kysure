<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Handler;

use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use App\Domain\Compliance\Service\MeetingAnalyzerInterface;
use App\Infrastructure\Compliance\Message\AnalyzeCompleteMeetingMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyzeCompleteMeetingHandler
{
    public function __construct(
        private MeetingRecordRepositoryInterface $recordingRepository,
        private MeetingAnalyzerInterface $analyzer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnalyzeCompleteMeetingMessage $message): void
    {
        // 1. On récupère la trace d'audit (qui contient l'URL S3 et le lien vers le dossier)
        $recording = $this->recordingRepository->findById($message->recordingId);

        if (!$recording instanceof \App\Domain\Compliance\Entity\MeetingRecording) {
            $this->logger->error('Analyse IA avortée : Enregistrement introuvable en base.', [
                'recordingId' => $message->recordingId,
            ]);

            return; // Impossible de continuer sans la trace
        }

        try {
            $this->logger->info('Lancement de l\'analyse Gemini via flux réseau...', [
                'recordingId' => $message->recordingId,
            ]);

            // 2. Le service s'occupe de TOUT (Extraction, Sauvegarde JSON brut, Dispatch Event)
            $this->analyzer->analyzeCompleteMeeting($recording);

            $this->logger->info('Appel Gemini terminé avec succès.', [
                'recordingId' => $message->recordingId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('CRASH lors de l\'analyse IA : ' . $e->getMessage(), [
                'recordingId' => $message->recordingId,
                'trace' => $e->getTraceAsString(),
            ]);

            // Le throw permet à Messenger de retenter (RetryStrategy) si l'API Google timeout
            throw $e;
        }
    }
}
