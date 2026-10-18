<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrUnavailableEvent;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Listener\AuditLogDocumentOcrUnavailableListener;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AuditLogDocumentOcrUnavailableListenerTest extends TestCase
{
    use ReflectionHelperTrait;

    public function testWritesAnAuditLogWithoutAnyExtractedPersonalData(): void
    {
        $workspace = $this->createEntityState(Workspace::class, ['name' => 'Cabinet Durand', 'slugId' => 'wrk_1']);
        $folder = $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'history' => [],
            'workspace' => $workspace,
        ]);
        $document = $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::ID_CARD,
            'acknowledgements' => new ArrayCollection(),
        ]);
        $document->markOcrUnavailable();

        $auditLogRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_DOCUMENT_OCR_UNAVAILABLE === $log->eventName
                && 'Cabinet Durand' === $log->workspace?->name
                && 'system_ocr' === $log->payload['actor_type']
                && 'id_card' === $log->payload['document_type']
                && 'comp_fol_1' === $log->payload['folder_slug_id']
                && !array_key_exists('ocr_data', $log->payload)));

        new AuditLogDocumentOcrUnavailableListener($auditLogRepository)(new DocumentOcrUnavailableEvent($document));
    }

    public function testTheNewEventTypeIsFullyDescribed(): void
    {
        $type = AuditEventType::KYC_DOCUMENT_OCR_UNAVAILABLE;

        self::assertNotSame('', $type->getLabel());
        self::assertNotSame('', $type->getCategory());
        self::assertNotSame('', $type->getBadgeColor());
        self::assertFalse($type->isVisibleToWorkspace());
    }
}
