<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Compliance\Listener\Document;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Event\ClientDocumentUploadedEvent;
use App\Domain\Compliance\Event\DocumentMalwareDetectedEvent;
use App\Domain\Compliance\Event\DocumentsRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Infrastructure\Compliance\Listener\Document\AuditLogClientDocumentUploadedListener;
use App\Infrastructure\Compliance\Listener\Document\AuditLogDocumentMalwareBlockedListener;
use App\Infrastructure\Compliance\Listener\Document\AuditLogDocumentsRequestedListener;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AuditLogClientDocumentsListenersTest extends TestCase
{
    use ReflectionHelperTrait;

    private AuditLogRepositoryInterface&MockObject $auditLogRepository;
    private ComplianceFolderRepositoryInterface&Stub $folderRepository;

    protected function setUp(): void
    {
        $this->auditLogRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $this->folderRepository = $this->createStub(ComplianceFolderRepositoryInterface::class);

        $workspace = $this->createEntityState(Workspace::class, ['name' => 'Cabinet Durand', 'slugId' => 'wrk_1']);
        $folder = $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-018',
            'workspace' => $workspace,
        ]);
        $this->folderRepository->method('findOneBySlugId')->willReturn($folder);
    }

    public function testTheRequestIsAuditedWithTheListOfRequestedDocumentTypes(): void
    {
        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_DOCUMENT_REQUESTED === $log->eventName
                && 'Cabinet Durand' === $log->workspace?->name
                && 'system' === $log->payload['actor_type']
                && 'comp_fol_1' === $log->payload['folder_slug_id']
                && ['kbis', 'rbe'] === $log->payload['document_types']));

        $listener = new AuditLogDocumentsRequestedListener($this->auditLogRepository, $this->folderRepository);
        $listener(new DocumentsRequestedEvent('comp_fol_1', ['kbis', 'rbe'], new \DateTimeImmutable('2026-10-18 10:00:00')));
    }

    public function testAFileRefusedByTheAntivirusIsAuditedWithItsFingerprintOnly(): void
    {
        $sha256 = hash('sha256', 'eicar');

        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_DOCUMENT_MALWARE_BLOCKED === $log->eventName
                && AuditEventType::KYC_DOCUMENT_MALWARE_BLOCKED->isCritical()
                && 'client' === $log->payload['actor_type']
                && 'Win.Test.EICAR_HDB-1' === $log->payload['detection']
                && $sha256 === $log->payload['file_sha256']
                && 'kbis' === $log->payload['document_type']
                && !array_key_exists('original_name', $log->payload)));

        $listener = new AuditLogDocumentMalwareBlockedListener($this->auditLogRepository, $this->folderRepository);
        $listener(new DocumentMalwareDetectedEvent('comp_fol_1', 'kbis', 'Win.Test.EICAR_HDB-1', $sha256, new \DateTimeImmutable('2026-10-18 10:06:00')));
    }

    public function testTheDepositIsAuditedWithTheFileFingerprintAndNoErasablePersonalData(): void
    {
        $sha256 = hash('sha256', 'contenu');

        $this->auditLogRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(static fn (AuditLog $log): bool => AuditEventType::KYC_DOCUMENT_UPLOADED === $log->eventName
                && 'client' === $log->payload['actor_type']
                && 'kbis' === $log->payload['document_type']
                && 'application/pdf' === $log->payload['mime_type']
                && 2048 === $log->payload['size']
                && $sha256 === $log->payload['file_sha256']
                && !array_key_exists('ip_address', $log->payload)
                && !array_key_exists('user_agent', $log->payload)
                && !array_key_exists('original_name', $log->payload)));

        $listener = new AuditLogClientDocumentUploadedListener($this->auditLogRepository, $this->folderRepository);
        $listener(new ClientDocumentUploadedEvent(
            documentId: 'doc-uuid',
            folderSlugId: 'comp_fol_1',
            documentType: 'kbis',
            mimeType: 'application/pdf',
            size: 2048,
            sha256: $sha256,
            uploadedAt: new \DateTimeImmutable('2026-10-18 10:05:00'),
        ));
    }
}
