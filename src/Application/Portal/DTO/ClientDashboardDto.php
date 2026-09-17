<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Suitability\Enum\InvestorProfileDashboardStatus;
use App\Domain\User\Enum\ClientPortalStatus;

readonly class ClientDashboardDto
{
    public function __construct(
        public string $clientFirstName,
        public string $cabinetName,
        public ClientPortalStatus $portalStatus,
        public int $pendingDocumentsCount,
        public ?ActiveFolderDto $activeFolder = null,
        /** E-mail de contact du cabinet, ou null si indisponible (on n'affiche alors aucun lien). */
        public ?string $cabinetContactEmail = null,
        public InvestorProfileDashboardStatus $investorProfileStatus = InvestorProfileDashboardStatus::NOT_STARTED,
    ) {
    }
}
