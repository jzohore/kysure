<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceDocument\Review;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\DocumentCorrectionsRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Signale au client, en un seul e-mail, toutes les pièces refusées qu'il ne connaît pas encore (dossier « à corriger »).
 * Idempotent : un double clic ne renvoie rien (aucune pièce ne reste à signaler → \DomainException).
 */
final readonly class RequestDocumentCorrectionsUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return int nombre de pièces signalées au client
     *
     * @throws \DomainException rien à signaler, ou dossier clôturé
     */
    public function __invoke(ComplianceFolder $folder): int
    {
        $advisor = $this->userProvider->getUser()->getFullName();

        $documents = $folder->requestCorrectionsFromClient($advisor);
        $this->transactionManager->transactional(function () use ($folder): void {
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new DocumentCorrectionsRequestedEvent($folder, $documents, $advisor));

        return count($documents);
    }
}
