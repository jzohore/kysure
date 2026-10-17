<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance\UseCase\MeetingRecord;

use App\Application\Compliance\DTO\Request\LogMeetingRecordingRequest;
use App\Application\Compliance\UseCase\ComplianceFolder\LogMeetingRecordingUseCase;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Entity\MeetingRecording;
use App\Domain\Compliance\Enum\MeetingProcessingStatus;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Compliance\Repository\MeetingRecordRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\TestCase;

final class LogMeetingRecordingUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private const string SESSION_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    public function testDebitsQuotaAndFlagsAnalysisInTheSameSaveAsTheAuditTrail(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['meetingSecondsConsumed' => 60]);
        $folder = $this->createEntityState(IndividualFolder::class, ['workspace' => $workspace]);

        $folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepository->method('findOneBySlugId')->willReturn($folder);

        $recordRepository = $this->createMock(MeetingRecordRepositoryInterface::class);
        $recordRepository->expects($this->once())
            ->method('save')
            ->willReturnCallback(static function (MeetingRecording $recording) use ($workspace, $folder): void {
                // 🛡️ Au moment du flush unique, le débit et le statut sont DÉJÀ posés.
                self::assertSame(180, $workspace->meetingSecondsConsumed);
                self::assertSame(MeetingProcessingStatus::ANALYZING, $folder->getMeetingProcessingStatus());
                self::assertSame(self::SESSION_ID, $recording->sessionId);
            });

        $recordingId = new LogMeetingRecordingUseCase($recordRepository, $folderRepository)(
            new LogMeetingRecordingRequest('comp_fol_x', self::SESSION_ID, 'documents/meetings/x/a.webm', 120)
        );

        self::assertNotSame('', $recordingId);
    }

    public function testThrowsWhenFolderIsUnknownAndDebitsNothing(): void
    {
        $folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $folderRepository->method('findOneBySlugId')->willReturn(null);

        $recordRepository = $this->createMock(MeetingRecordRepositoryInterface::class);
        $recordRepository->expects($this->never())->method('save');

        $this->expectException(ComplianceFolderNotFoundException::class);

        new LogMeetingRecordingUseCase($recordRepository, $folderRepository)(
            new LogMeetingRecordingRequest('nope', self::SESSION_ID, 'p', 10)
        );
    }
}
