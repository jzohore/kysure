<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrProcessedEvent;
use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use App\Domain\Shared\Port\RealTimeNotifierInterface;
use App\Infrastructure\Compliance\Listener\NotifyFolderOnOcrAnalysisListener;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class NotifyFolderOnOcrAnalysisListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    private function document(): ComplianceDocument
    {
        $folder = $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_1', 'reference' => 'DOS-1', 'history' => []]);

        return $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::KBIS,
            'acknowledgements' => new ArrayCollection(),
        ]);
    }

    public function testNotifiesTheFolderTopicWhenAnAnalysisIsDone(): void
    {
        $notifier = $this->createMock(RealTimeNotifierInterface::class);
        $notifier->expects($this->once())->method('notify')->with('folder_comp_fol_1', ['action' => 'document_ocr_analysed']);

        new NotifyFolderOnOcrAnalysisListener($notifier, new NullLogger())
            ->onProcessed(new DocumentOcrProcessedEvent($this->document(), [], true));
    }

    public function testNotifiesTheFolderTopicWhenTheAnalysisIsUnavailable(): void
    {
        $notifier = $this->createMock(RealTimeNotifierInterface::class);
        $notifier->expects($this->once())->method('notify')->with('folder_comp_fol_1', ['action' => 'document_ocr_analysed']);

        new NotifyFolderOnOcrAnalysisListener($notifier, new NullLogger())
            ->onUnavailable(new DocumentOcrUnavailableEvent($this->document()));
    }

    public function testAnUnreachableMercureHubNeverBreaksTheAnalysis(): void
    {
        $notifier = $this->createMock(RealTimeNotifierInterface::class);
        $notifier->expects($this->once())->method('notify')->willThrowException(new \RuntimeException('hub down'));

        new NotifyFolderOnOcrAnalysisListener($notifier, new NullLogger())
            ->onProcessed(new DocumentOcrProcessedEvent($this->document(), [], true));

        $this->addToAssertionCount(1);
    }
}
