<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\ComplianceFolderRejectedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Déclare un dossier non conforme (LCB-FT). L'autorisation relève d'un voter au niveau du
 * contrôleur/composant ; ce use case se limite aux règles métier.
 */
final readonly class RejectComplianceFolderUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(ComplianceFolder $folder, string $reason): void
    {
        $user = $this->userProvider->getUser();

        $this->transactionManager->transactional(function () use ($folder, $reason, $user): void {
            $folder->reject($reason, $user);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new ComplianceFolderRejectedEvent(
            folderSlugId: $folder->slugId,
            reason: $reason,
            rejectedByName: $user->getFullName(),
        ));
    }
}
