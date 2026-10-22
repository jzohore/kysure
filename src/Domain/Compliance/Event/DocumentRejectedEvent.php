<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;

/**
 * Décision du conseiller sur une pièce : refusée (motif porté par la pièce). Acte tracé dans l'audit inaltérable.
 */
final readonly class DocumentRejectedEvent
{
    public function __construct(
        public ComplianceDocument $document,
        public string $advisorName,
    ) {
    }
}
