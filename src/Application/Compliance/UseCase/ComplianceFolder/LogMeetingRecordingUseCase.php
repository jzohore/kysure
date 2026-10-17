<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Application\Compliance\DTO\Request\LogMeetingRecordingRequest;
use App\Domain\Compliance\Entity\MeetingRecording;
use App\Domain\Compliance\Enum\MeetingProcessingStatus;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use Webmozart\Assert\Assert;

final readonly class LogMeetingRecordingUseCase
{
    public function __construct(
        private MeetingRecordRepositoryInterface $recordRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(LogMeetingRecordingRequest $request): string
    {
        $folder = $this->folderRepository->findOneBySlugId($request->folderSlugId);

        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withId($request->folderSlugId);
        }

        // Création de la piste d'audit immuable (l'UUID est généré ici)
        $recording = MeetingRecording::initialize(
            $folder,
            $request->sessionId,
            $request->s3Path,
            $request->consumedSeconds
        );

        // 🛡️ Atomicité : débit du quota, statut d'analyse et trace d'audit partent dans UN SEUL flush
        // (une transaction Doctrine). La trace porte la contrainte unique (session, dossier) qui sert de
        // clé d'idempotence : un rejeu ne peut ni débiter deux fois ni laisser un état intermédiaire.
        $folder->workspace->consumeMeetingSeconds($request->consumedSeconds);
        $folder->setMeetingProcessingStatus(MeetingProcessingStatus::ANALYZING);

        $this->recordRepository->save($recording);

        Assert::notNull($recording->id);

        return $recording->id->toRfc4122();
    }
}
