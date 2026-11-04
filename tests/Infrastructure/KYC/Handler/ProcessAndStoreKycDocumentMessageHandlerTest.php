<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\KYC\Handler;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\KYC\Handler\ProcessAndStoreKycDocumentMessageHandler;
use App\Infrastructure\KYC\Message\ProcessAndStoreKycDocumentMessage;
use App\Infrastructure\Service\ImageOptimizer;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class ProcessAndStoreKycDocumentMessageHandlerTest extends TestCase
{
    use ReflectionHelperTrait;

    private ComplianceDocumentRepositoryInterface&MockObject $documents;
    private DocumentStorageInterface&MockObject $storage;
    private ProcessAndStoreKycDocumentMessageHandler $handler;
    private string $localFile;

    protected function setUp(): void
    {
        $this->documents = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $this->storage = $this->createMock(DocumentStorageInterface::class);
        $this->localFile = tempnam(sys_get_temp_dir(), 'kyc_store_') ?: '';
        file_put_contents($this->localFile, "%PDF-1.4\nsynthetic");

        $this->handler = new ProcessAndStoreKycDocumentMessageHandler(
            $this->documents,
            $this->createFolderRepository(),
            $this->storage,
            $this->createStub(ImageOptimizer::class),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->localFile);
    }

    private function createFolderRepository(): ComplianceFolderRepositoryInterface&Stub
    {
        $folder = $this->createEntityState(BusinessFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'history' => [],
        ]);
        $repository = $this->createStub(ComplianceFolderRepositoryInterface::class);
        $repository->method('findById')->willReturn($folder);

        return $repository;
    }

    private function document(): ComplianceDocument
    {
        $document = $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_1',
            'folder' => $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_1', 'reference' => 'DOS-1', 'history' => []]),
            'type' => DocumentType::ID_CARD,
            'status' => DocumentStatus::PROCESSING,
            'storagePath' => 'compliance_folders/comp_fol_1/old.pdf',
            'acknowledgements' => new ArrayCollection(),
        ]);
        $this->documents->method('findById')->willReturn($document);

        return $document;
    }

    private function message(bool $isBackSide = false, ?string $oldPath = 'compliance_folders/comp_fol_1/old.pdf'): ProcessAndStoreKycDocumentMessage
    {
        return new ProcessAndStoreKycDocumentMessage(
            documentId: Uuid::v7()->toString(),
            folderId: Uuid::v7()->toString(),
            localTempPath: $this->localFile,
            mimeType: 'application/pdf',
            originalName: 'cni.pdf',
            size: 18,
            oldStoragePath: $oldPath,
            isBackSide: $isBackSide,
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAFailedDeletionOfTheReplacedFileNeverFailsTheDeposit(): void
    {
        $document = $this->document();
        $this->storage->method('store')->willReturn('compliance_folders/comp_fol_1/new.pdf');
        $this->storage->method('delete')->willThrowException(new \RuntimeException('S3 lent'));

        ($this->handler)($this->message());

        self::assertSame('compliance_folders/comp_fol_1/new.pdf', $document->storagePath);
        self::assertSame(DocumentStatus::UPLOADED, $document->status);
    }

    public function testTheReplacedFileIsDeletedOnlyOnceTheNewOneIsRecorded(): void
    {
        $document = $this->document();
        $this->storage->method('store')->willReturn('compliance_folders/comp_fol_1/new.pdf');
        $this->documents->expects($this->once())->method('save')->with($document);
        $this->storage->expects($this->once())->method('delete')->with('compliance_folders/comp_fol_1/old.pdf');

        ($this->handler)($this->message());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTheBackSideIsStoredWithoutTouchingTheFrontFile(): void
    {
        $document = $this->document();
        $this->storage->method('store')->willReturn('compliance_folders/comp_fol_1/verso.pdf');

        ($this->handler)($this->message(isBackSide: true, oldPath: null));

        self::assertSame('compliance_folders/comp_fol_1/verso.pdf', $document->backStoragePath);
        self::assertSame('compliance_folders/comp_fol_1/old.pdf', $document->storagePath);
    }
}
