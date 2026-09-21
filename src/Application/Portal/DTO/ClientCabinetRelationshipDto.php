<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Suitability\Enum\InvestorProfileDashboardStatus;

/**
 * Une relation active du client avec un cabinet donné : un client peut en avoir plusieurs à
 * la fois ({@see \App\Domain\User\Entity\Client::$workspaces}), chacune avec son propre
 * dossier, ses propres pièces en attente et son propre profil investisseur — jamais mélangés.
 */
readonly class ClientCabinetRelationshipDto
{
    public function __construct(
        public ActiveFolderDto $activeFolder,
        public ?string $cabinetContactEmail,
        public int $pendingDocumentsCount,
        public InvestorProfileDashboardStatus $investorProfileStatus,
    ) {
    }
}
