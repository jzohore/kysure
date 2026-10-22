<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;

/**
 * Le conseiller demande au client de refournir les pièces refusées : un seul e-mail groupé part au client.
 */
final readonly class DocumentCorrectionsRequestedEvent
{
    /**
     * @param list<ComplianceDocument> $documents pièces signalées par cette demande (jamais déjà signalées)
     */
    public function __construct(
        public ComplianceFolder $folder,
        public array $documents,
        public string $advisorName,
    ) {
    }
}
