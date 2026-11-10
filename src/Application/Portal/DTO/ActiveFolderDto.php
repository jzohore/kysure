<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\User\Enum\ClientPortalStatus;

readonly class ActiveFolderDto
{
    public function __construct(
        public string $id, // Utilise toujours le slugId/UUID pour la sécurité des liens
        public string $title, // Ex: "Mise à jour réglementaire" ou "KYC Initial"
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public \DateTimeImmutable $openedAt,
        public ClientPortalStatus $status,
        public string $workspaceName,
    ) {
    }
}
