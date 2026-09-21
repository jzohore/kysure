<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

readonly class ClientDashboardDto
{
    /**
     * @param list<ClientCabinetRelationshipDto> $cabinetRelationships toutes les relations
     *                                                                 actives du client, un cabinet peut en avoir plusieurs à la fois
     */
    public function __construct(
        public string $clientFirstName,
        public array $cabinetRelationships,
    ) {
    }
}
