<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le dossier a été rejeté (non conforme LCB-FT) par un responsable du cabinet.
 */
final readonly class ComplianceFolderRejectedEvent
{
    public function __construct(
        public string $folderSlugId,
        public string $reason,
        public string $rejectedByName,
    ) {
    }
}
