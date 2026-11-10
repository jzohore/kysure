<?php

declare(strict_types=1);

namespace App\Application\Portal\UseCase;

use App\Application\Portal\DTO\ActiveFolderDto;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Enum\ClientPortalStatus;

readonly class GetClientFoldersUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ComplianceDocumentRepositoryInterface $documentRepository,
    ) {
    }

    /**
     * Récupère et transforme la liste des dossiers actifs pour le portail client.
     *
     * @return list<ActiveFolderDto>
     */
    public function __invoke(Client $client): array
    {
        $folders = $this->folderRepository->findAllActiveForClient($client);

        return array_map(fn (ComplianceFolder $folder): ActiveFolderDto => new ActiveFolderDto(
            id: $folder->slugId,
            title: $folder->reference ?? 'Dossier de conformité',
            openedAt: $folder->createdAt,
            status: ClientPortalStatus::resolve($folder->status, $this->documentRepository->countPendingForFolder($folder)),
            workspaceName: $folder->workspace->name,
        ), $folders);
    }
}
