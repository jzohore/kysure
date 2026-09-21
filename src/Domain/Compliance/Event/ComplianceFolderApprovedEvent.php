<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le dossier a été déclaré conforme (LCB-FT) par un responsable du cabinet.
 */
final readonly class ComplianceFolderApprovedEvent
{
    public function __construct(
        public string $folderSlugId,
        public string $riskLevel,
        public string $approvedByName,
    ) {
    }
}
