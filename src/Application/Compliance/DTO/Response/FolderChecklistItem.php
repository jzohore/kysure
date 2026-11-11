<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Response;

/**
 * Une étape de la checklist de pilotage d'un dossier (lot 8) : faite ou non, avec un détail
 * lisible (date, version, ce qui manque) et, si utile, l'ancre de la section où agir.
 */
final readonly class FolderChecklistItem
{
    public function __construct(
        public string $label,
        public bool $done,
        public string $detail,
        public ?string $anchor = null,
        /** Étape exigée pour approuver le dossier (règle de blocage). */
        public bool $requiredForApproval = false,
    ) {
    }
}
