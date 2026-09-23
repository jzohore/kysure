<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Application\Compliance\UseCase\ComplianceFolder\BuildHolisticMeetingReportUseCase;
use App\Domain\Compliance\Enum\MeetingProcessingStatus;
use App\Domain\Compliance\Event\MeetingAnalysisCompletedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class UpdateFolderAfterAnalysisSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private MeetingRecordRepositoryInterface $recordingRepository,
        private EntityManagerInterface $entityManager,
        private BuildHolisticMeetingReportUseCase $buildHolisticMeetingReportUseCase,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MeetingAnalysisCompletedEvent::class => 'onAnalysisCompleted',
        ];
    }

    public function onAnalysisCompleted(MeetingAnalysisCompletedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        $recording = $this->recordingRepository->findById($event->recordingId);

        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder || !$recording instanceof \App\Domain\Compliance\Entity\MeetingRecording || !$recording->geminiRawOutput) {
            return;
        }

        // 🚀 On délègue la fusion intelligente à notre Use Case déterministe
        $holisticReportDto = ($this->buildHolisticMeetingReportUseCase)($folder);

        // On sauvegarde le JSON global formaté pour l'interface UI Twig
        $reportData = $holisticReportDto->toArray();
        $reportData['analyzedAt'] = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $folder->setPostMeetingReport($reportData);

        // Mise à jour du statut pour débloquer l'UI
        $folder->setMeetingProcessingStatus(MeetingProcessingStatus::DONE);

        $this->entityManager->flush();
    }
}
