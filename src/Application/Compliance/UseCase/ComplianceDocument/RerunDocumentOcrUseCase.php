<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceDocument;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\DocumentOcrRerunRequestedEvent;
use App\Domain\Compliance\Exception\Document\DocumentNotFoundException;
use App\Domain\Compliance\Exception\Document\InvalidDocumentFolderException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Relance « à la volée » de l'analyse automatique d'une pièce déjà reçue (bouton opérateur de la fiche dossier).
 *
 * L'autorisation (rôle opérateur) est portée par le contrôleur ; ici : appartenance au dossier, garde métier
 * de l'entité, enregistrement puis événement (la mise en file est un effet de bord isolé dans un listener).
 */
readonly class RerunDocumentOcrUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @throws \DomainException si la pièce ne peut pas être relancée (déjà en cours, non analysable, tranchée)
     */
    public function __invoke(string $documentId, ComplianceFolder $folder): void
    {
        $document = $this->repository->findById($documentId);

        if (!$document instanceof \App\Domain\Compliance\Entity\ComplianceDocument) {
            throw DocumentNotFoundException::withId($documentId);
        }

        if ($document->folder !== $folder) {
            throw InvalidDocumentFolderException::forDocument($documentId, $folder->slugId);
        }

        $document->requestOcrRerun();
        $this->repository->save($document);

        $this->eventDispatcher->dispatch(new DocumentOcrRerunRequestedEvent($document));
    }
}
