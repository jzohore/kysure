<?php

declare(strict_types=1);

namespace App\Tests\Application\Compliance\UseCase\ComplianceDocument\Review;

use App\Application\Compliance\UseCase\ComplianceDocument\Review\DocumentReviewUseCase;
use App\Application\Compliance\UseCase\ComplianceDocument\Review\RequestDocumentCorrectionsUseCase;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentRejectionReason;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Compliance\Event\DocumentCorrectionsRequestedEvent;
use App\Domain\Compliance\Event\DocumentRejectedEvent;
use App\Domain\Compliance\Event\DocumentReviewCancelledEvent;
use App\Domain\Compliance\Event\DocumentValidatedEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\User\Entity\User;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class DocumentReviewUseCaseTest extends TestCase
{
    use ReflectionHelperTrait;

    private IndividualFolder $folder;
    private ComplianceDocument $document;

    protected function setUp(): void
    {
        $this->folder = $this->folder(ComplianceFolderStatus::IN_REVIEW);
        $this->document = $this->documentIn($this->folder);
    }

    private function folder(ComplianceFolderStatus $status): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_fol_' . random_int(1, 9999),
            'reference' => 'DOS-1',
            'status' => $status,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
    }

    private function documentIn(IndividualFolder $folder): ComplianceDocument
    {
        $document = $this->createEntityState(ComplianceDocument::class, [
            'id' => Uuid::v7(),
            'slugId' => 'comp_doc_1',
            'folder' => $folder,
            'type' => DocumentType::ID_CARD,
            'status' => DocumentStatus::UPLOADED,
            'storagePath' => 'kyc/cni.jpg',
            'isMandatory' => true,
            'acknowledgements' => new ArrayCollection(),
        ]);
        $folder->documents->add($document);

        return $document;
    }

    private function useCase(?ComplianceDocumentRepositoryInterface $repository = null, ?EventDispatcherInterface $dispatcher = null): DocumentReviewUseCase
    {
        if (!$repository instanceof ComplianceDocumentRepositoryInterface) {
            $repository = $this->createStub(ComplianceDocumentRepositoryInterface::class);
            $repository->method('findById')->willReturn($this->document);
        }

        return new DocumentReviewUseCase($repository, $this->transactions(), $this->advisor(), $dispatcher ?? $this->createStub(EventDispatcherInterface::class));
    }

    private function transactions(): TransactionManagerInterface
    {
        $transactions = $this->createStub(TransactionManagerInterface::class);
        $transactions->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $transactions;
    }

    private function advisor(): CurrentUserProvider
    {
        $provider = $this->createStub(CurrentUserProvider::class);
        $provider->method('getUser')->willReturn($this->createEntityState(User::class, ['id' => Uuid::v7(), 'firstName' => 'Marie', 'lastName' => 'Curie']));

        return $provider;
    }

    public function testValidationIsSavedThenAudited(): void
    {
        $repository = $this->createMock(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn($this->document);
        $repository->expects($this->once())->method('save')->with($this->document);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->callback(
            fn (object $event): bool => $event instanceof DocumentValidatedEvent && $event->document === $this->document && str_contains(mb_strtoupper($event->advisorName), 'CURIE'),
        ));

        $this->useCase($repository, $dispatcher)->validate('doc', $this->folder);

        self::assertSame(DocumentStatus::VALID, $this->document->status);
    }

    public function testRejectionWithAListedReason(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->isInstanceOf(DocumentRejectedEvent::class));

        $this->useCase(dispatcher: $dispatcher)->reject('doc', $this->folder, 'expired', null);

        self::assertSame(DocumentRejectionReason::EXPIRED, $this->document->rejectionReasonCode);
    }

    public function testAnUnknownReasonIsRefusedBeforeAnyChange(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        try {
            $this->useCase(dispatcher: $dispatcher)->reject('doc', $this->folder, 'pas-un-motif', null);
            self::fail('Une exception était attendue.');
        } catch (\DomainException) {
            self::assertSame(DocumentStatus::UPLOADED, $this->document->status);
        }
    }

    public function testCancellationIsAuditedWithTheCancelledDecision(): void
    {
        $this->document->validateByAdvisor('Marie Curie');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->callback(
            static fn (object $event): bool => $event instanceof DocumentReviewCancelledEvent
                && DocumentStatus::VALID === $event->cancelledDecision
                && 'Validée par erreur' === $event->reason,
        ));

        $this->useCase(dispatcher: $dispatcher)->cancel('doc', $this->folder, '  Validée par erreur ');
    }

    public function testADocumentOfAnotherFolderIsRefused(): void
    {
        $this->expectException(InvalidDocumentFolderException::class);

        $this->useCase()->validate('doc', $this->folder(ComplianceFolderStatus::IN_REVIEW));
    }

    public function testAnUnknownDocumentIsRefused(): void
    {
        $repository = $this->createStub(ComplianceDocumentRepositoryInterface::class);
        $repository->method('findById')->willReturn(null);

        $this->expectException(DocumentNotFoundException::class);

        $this->useCase($repository)->validate('nope', $this->folder);
    }

    public function testAClosedFolderIsFrozen(): void
    {
        $approved = $this->folder(ComplianceFolderStatus::APPROVED);
        $this->document = $this->documentIn($approved);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('clôturé');

        $this->useCase()->validate('doc', $approved);
    }

    public function testCorrectionsRequestSendsOneEventForAllUnsentRejections(): void
    {
        $this->document->rejectByAdvisor(DocumentRejectionReason::ILLEGIBLE, null, 'Marie Curie');
        $folderRepository = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $folderRepository->expects($this->once())->method('save')->with($this->folder);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')->with($this->callback(
            fn (object $event): bool => $event instanceof DocumentCorrectionsRequestedEvent && [$this->document] === $event->documents,
        ));

        $count = new RequestDocumentCorrectionsUseCase($folderRepository, $this->transactions(), $this->advisor(), $dispatcher)($this->folder);

        self::assertSame(1, $count);
        self::assertSame(ComplianceFolderStatus::NEEDS_CORRECTION, $this->folder->status);
    }

    public function testNothingToSignalSendsNothing(): void
    {
        $folderRepository = $this->createMock(ComplianceFolderRepositoryInterface::class);
        $folderRepository->expects($this->never())->method('save');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\DomainException::class);

        new RequestDocumentCorrectionsUseCase($folderRepository, $this->transactions(), $this->advisor(), $dispatcher)($this->folder);
    }
}
