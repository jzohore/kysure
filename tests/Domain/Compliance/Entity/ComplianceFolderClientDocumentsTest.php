<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Entity;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentType;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ComplianceFolderClientDocumentsTest extends TestCase
{
    use ReflectionHelperTrait;

    private function folder(ComplianceFolderStatus $status = ComplianceFolderStatus::AWAITING_CLIENT): ComplianceFolder
    {
        return $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-2026-018',
            'status' => $status,
            'history' => [],
            'documents' => new ArrayCollection(),
        ]);
    }

    private function received(ComplianceDocument $document): ComplianceDocument
    {
        $document->markAsUploaded('compliance_folders/comp_fol_1/doc.pdf', 'doc.pdf', 'application/pdf', 1024);

        return $document;
    }

    public function testRequestsEveryMandatoryDocumentNotYetProvided(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requireDocument(DocumentType::RBE, isMandatory: true);
        $folder->requireDocument(DocumentType::FINANCIAL_STATEMENTS, isMandatory: false);

        $requested = $folder->requestMandatoryDocumentsFromClient();

        self::assertSame([DocumentType::KBIS, DocumentType::RBE], $requested);
        self::assertCount(2, $folder->documentsAwaitingClient());
        self::assertCount(1, $folder->history, 'La demande est tracée dans l\'historique du dossier.');
    }

    public function testRequestingTwiceIsIdempotent(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);

        $folder->requestMandatoryDocumentsFromClient();

        self::assertSame([], $folder->requestMandatoryDocumentsFromClient());
        self::assertCount(1, $folder->history, 'Aucune seconde entrée d\'historique au rejeu.');
    }

    public function testAlreadyReceivedDocumentsAreNotRequestedAgain(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requireDocument(DocumentType::RBE, isMandatory: true);
        $this->received($folder->documents->first());

        self::assertSame([DocumentType::RBE], $folder->requestMandatoryDocumentsFromClient());
    }

    public function testTheDerIsNeverRequestedFromTheClient(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::DER, isMandatory: true);

        self::assertSame([], $folder->requestMandatoryDocumentsFromClient());
        self::assertSame([], $folder->documentsAwaitingClient());
        self::assertTrue($folder->hasAllMandatoryDocumentsReceived());
    }

    public function testASimplyRequestedDocumentIsNotReceivedSoTheFolderCannotBeSubmitted(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requestMandatoryDocumentsFromClient();

        // Demandée ≠ reçue : l'ancienne règle (isComplete) laissait passer ce cas.
        self::assertTrue($folder->hasAllMandatoryDocuments());
        self::assertFalse($folder->hasAllMandatoryDocumentsReceived());
        self::assertFalse($folder->canBeSubmitted());
    }

    public function testTheFolderCanBeSubmittedOnceEveryMandatoryDocumentIsReceived(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requireDocument(DocumentType::RBE, isMandatory: true);
        $folder->requestMandatoryDocumentsFromClient();

        foreach ($folder->documents as $document) {
            $this->received($document);
        }

        self::assertTrue($folder->canBeSubmitted());
        self::assertSame([], $folder->documentsAwaitingClient());
    }

    public function testOptionalMissingDocumentsDoNotBlockSubmission(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requireDocument(DocumentType::FINANCIAL_STATEMENTS, isMandatory: false);
        $folder->requestMandatoryDocumentsFromClient();
        $this->received($folder->documents->first());

        self::assertTrue($folder->canBeSubmitted());
    }

    public function testCollectionIsOnlyOpenWhileTheClientHasSomethingToDo(): void
    {
        foreach ([ComplianceFolderStatus::AWAITING_CLIENT, ComplianceFolderStatus::PENDING_DOCS, ComplianceFolderStatus::NEEDS_CORRECTION] as $open) {
            self::assertTrue($this->folder($open)->canReceiveClientDocuments(), $open->value);
        }

        foreach ([ComplianceFolderStatus::DRAFT, ComplianceFolderStatus::DER_SENT, ComplianceFolderStatus::IN_REVIEW, ComplianceFolderStatus::APPROVED, ComplianceFolderStatus::ARCHIVED] as $closed) {
            self::assertFalse($this->folder($closed)->canReceiveClientDocuments(), $closed->value);
        }
    }

    public function testADocumentBeingProcessedOrValidatedCannotBeReplacedByTheClient(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::KBIS, isMandatory: true);
        $folder->requestMandatoryDocumentsFromClient();
        $document = $folder->documents->first();

        self::assertTrue($document->canBeReplacedByClient());

        $document->markAsProcessed();
        self::assertFalse($document->canBeReplacedByClient());
        self::assertSame([], $folder->documentsAwaitingClient(), 'Une pièce en cours de traitement n\'attend plus le client.');
    }

    public function testADocumentNotRequestedFromTheClientCannotBeDeposited(): void
    {
        $folder = $this->folder();
        $folder->requireDocument(DocumentType::FINANCIAL_STATEMENTS, isMandatory: false);

        self::assertFalse($folder->documents->first()->canBeReplacedByClient());
    }
}
