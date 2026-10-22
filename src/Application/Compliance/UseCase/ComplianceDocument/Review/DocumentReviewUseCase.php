<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceDocument\Review;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\DocumentRejectionReason;
use App\Domain\Compliance\Event\DocumentRejectedEvent;
use App\Domain\Compliance\Event\DocumentReviewCancelledEvent;
use App\Domain\Compliance\Event\DocumentValidatedEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Décision du conseiller sur une pièce : valider, refuser (motif), ou annuler sa décision (motif).
 *
 * L'autorisation (« valider des actes » du cabinet) est portée par le contrôleur ; ici : appartenance de la pièce
 * au dossier, dossier encore ouvert, règles de l'entité, enregistrement puis événement (audit).
 */
final readonly class DocumentReviewUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $repository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws \DomainException
     */
    public function validate(string $documentId, ComplianceFolder $folder): void
    {
        $document = $this->documentOf($documentId, $folder);
        $advisor = $this->userProvider->getUser()->getFullName();

        $document->validateByAdvisor($advisor);
        $this->save($document);

        $this->eventDispatcher->dispatch(new DocumentValidatedEvent($document, $advisor));
    }

    /**
     * @throws \DomainException motif inconnu, précision manquante, ou pièce non refusable
     */
    public function reject(string $documentId, ComplianceFolder $folder, string $reasonCode, ?string $comment): void
    {
        $reason = DocumentRejectionReason::tryFrom($reasonCode)
            ?? throw new \DomainException('Choisissez un motif de refus.');

        $document = $this->documentOf($documentId, $folder);
        $advisor = $this->userProvider->getUser()->getFullName();

        $document->rejectByAdvisor($reason, $comment, $advisor);
        $this->save($document);

        $this->eventDispatcher->dispatch(new DocumentRejectedEvent($document, $advisor));
    }

    /**
     * @throws \DomainException décision non annulable, ou motif manquant
     */
    public function cancel(string $documentId, ComplianceFolder $folder, string $reason): void
    {
        $document = $this->documentOf($documentId, $folder);
        $advisor = $this->userProvider->getUser()->getFullName();

        $cancelled = $document->cancelReview($reason, $advisor);
        $this->save($document);

        $this->eventDispatcher->dispatch(new DocumentReviewCancelledEvent($document, $cancelled, trim($reason), $advisor));
    }

    private function documentOf(string $documentId, ComplianceFolder $folder): ComplianceDocument
    {
        $document = $this->repository->findById($documentId);

        if (!$document instanceof ComplianceDocument) {
            throw DocumentNotFoundException::withId($documentId);
        }

        if ($document->folder !== $folder) {
            throw InvalidDocumentFolderException::forDocument($documentId, $folder->slugId);
        }

        if (!$folder->isOpenForDocumentReview()) {
            throw new \DomainException('Ce dossier est clôturé : ses pièces ne peuvent plus être modifiées.');
        }

        return $document;
    }

    private function save(ComplianceDocument $document): void
    {
        $this->transactionManager->transactional(function () use ($document): void {
            $this->repository->save($document);
        });
    }
}
