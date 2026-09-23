<?php

declare(strict_types=1);

namespace App\Application\Workspace\UseCase;

use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Infrastructure\Compliance\Message\FinalizeMeetingAudioMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class StopAudioUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(string $slugId, string $sessionId, int $consumedSeconds): void
    {
        $folder = $this->complianceFolderRepository->findOneBySlugId($slugId);

        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withId($slugId);
        }

        $folder->workspace->assertMeetingRecordingIsAllowed();

        // 🚀 On transmet le sessionId au Worker qui ira assembler les chunks
        // de ce dossier S3 spécifique.
        $this->messageBus->dispatch(new FinalizeMeetingAudioMessage(
            folderSlugId: $slugId,
            sessionId: $sessionId,
            consumedSeconds: $consumedSeconds
        ));
    }
}
