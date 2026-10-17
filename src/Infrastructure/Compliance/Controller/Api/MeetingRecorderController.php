<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller\Api;

use App\Application\Workspace\UseCase\AppendAudioChunkUseCase;
use App\Application\Workspace\UseCase\StopAudioUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Webmozart\Assert\Assert;

final class MeetingRecorderController extends AbstractController
{
    public function __construct(
        private readonly AppendAudioChunkUseCase $appendAudioChunkUseCase,
        private readonly StopAudioUseCase $stopAudioUseCase,
    ) {
    }

    // 🛡️ Autorisation : membre du cabinet ET dossier visible (liste blanche de confidentialité).
    #[Route('/api/meeting/{slugId}/chunk', name: 'app_api_meeting_chunk', methods: ['POST'])]
    #[IsGranted(ComplianceFolderVoter::EDIT, subject: 'folder', message: 'Accès refusé à ce dossier.')]
    public function receiveChunk(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $folder,
        Request $request,
    ): JsonResponse {
        $slugId = $folder->slugId;

        $audioFile = $request->files->get('audio_chunk');
        $chunkIndex = (int) $request->request->get('chunk_index', 0);
        $mimeType = (string) $request->request->get('mime_type');
        $sessionId = (string) $request->request->get('session_id');

        if (!$audioFile) {
            return $this->json(['error' => 'Aucun flux audio reçu.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            Assert::uuid($sessionId, 'Identifiant de session manquant ou invalide.');

            $this->appendAudioChunkUseCase->execute($slugId, $sessionId, $audioFile, $chunkIndex, $mimeType);

            return $this->json(['status' => 'Chunk reçu.']);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_PAYMENT_REQUIRED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/api/meeting/{slugId}/stop', name: 'app_api_meeting_stop', methods: ['POST'])]
    #[IsGranted(ComplianceFolderVoter::EDIT, subject: 'folder', message: 'Accès refusé à ce dossier.')]
    public function stopAndAnalyze(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $folder,
        Request $request,
    ): JsonResponse {
        $slugId = $folder->slugId;

        $consumedSeconds = (int) $request->request->get('consumed_seconds', 0);
        $sessionId = (string) $request->request->get('session_id');

        try {
            Assert::uuid($sessionId, 'Identifiant de session manquant ou invalide.');

            ($this->stopAudioUseCase)($slugId, $sessionId, $consumedSeconds);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $this->json(['status' => 'Audio en cours de finalisation.']);
    }
}
