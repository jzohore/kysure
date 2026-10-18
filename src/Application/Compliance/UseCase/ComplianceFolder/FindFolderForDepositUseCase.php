<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Charge le dossier visé par un dépôt de pièce, AVANT toute autorisation.
 * L'appartenance au client connecté est vérifiée par le voter, pas ici.
 */
readonly class FindFolderForDepositUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    /**
     * @throws ComplianceFolderNotFoundException identifiant mal formé ou dossier inexistant
     */
    public function __invoke(string $folderId): ComplianceFolder
    {
        if (!Uuid::isValid($folderId)) {
            throw ComplianceFolderNotFoundException::withId($folderId);
        }

        return $this->folderRepository->findById(Uuid::fromString($folderId));
    }
}
