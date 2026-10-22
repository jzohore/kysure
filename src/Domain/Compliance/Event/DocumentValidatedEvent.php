<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;

/**
 * Décision du conseiller sur une pièce : validée. Acte tracé dans l'audit inaltérable.
 */
final readonly class DocumentValidatedEvent
{
    public function __construct(
        public ComplianceDocument $document,
        public string $advisorName,
    ) {
    }
}
