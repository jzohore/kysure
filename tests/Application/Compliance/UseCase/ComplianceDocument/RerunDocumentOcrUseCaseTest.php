<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance\UseCase\ComplianceDocument;

use App\Application\Compliance\UseCase\ComplianceDocument\RerunDocumentOcrUseCase;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentOcrRerunRequestedEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RerunDocumentOcrUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private BusinessFolder $folder;

    protected function setUp(): void
    {
        $this->folder = $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_1', 'reference' => 'DOS-1', 'history' => []]);
    }

    private function document(?BusinessFolder $folder = null): ComplianceDocument
    {
        return $this->createEntityState(ComplianceDocument::class, [
            'slugId' => 'comp_doc_1',
            'folder' => $folder ?? $this->folder,
            'type' => DocumentType::PROOF_OF_ADDRESS,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'kyc/doc.pdf',
            'uploadedAt' => new \DateTimeImmutable('-3 days'),
            'ocrData' => ['holder_name' => 'MARTIN'],
            'acknowledgements' => new ArrayCollection(),
        ]);
    }

    public function testResetsTheAnalysisSavesThenAnnouncesTheRerun(): void
    {
        $document = $this->document();
        $repository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn($document);
        $repository->expects($this->once())->method('save')->with($document);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->callback(
            static fn (object $event): bool => $event instanceof DocumentOcrRerunRequestedEvent && $event->document === $document,
        ));

        new RerunDocumentOcrUseCase($repository, $dispatcher)('doc-id', $this->folder);

        self::assertNull($document->ocrData);
    }

    public function testNothingIsSavedNorDispatchedWhenTheRerunIsRefused(): void
    {
        $document = $this->document();
        $document->requestOcrRerun(); // une analyse est déjà demandée

        $repository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn($document);
        $repository->expects($this->never())->method('save');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);

        new RerunDocumentOcrUseCase($repository, $dispatcher)('doc-id', $this->folder);
    }

    public function testUnknownDocument(): void
    {
        $repository = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        $this->expectException(DocumentNotFoundException::class);

        new RerunDocumentOcrUseCase($repository, $this->createStub(EventDispatcherInterface::class))('nope', $this->folder);
    }

    public function testADocumentOfAnotherFolderIsRefused(): void
    {
        $other = $this->createEntityState(BusinessFolder::class, ['slugId' => 'comp_fol_2', 'reference' => 'DOS-2', 'history' => []]);
        $repository = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn($this->document($other));

        $this->expectException(InvalidDocumentFolderException::class);

        new RerunDocumentOcrUseCase($repository, $this->createStub(EventDispatcherInterface::class))('doc-id', $this->folder);
    }
}
