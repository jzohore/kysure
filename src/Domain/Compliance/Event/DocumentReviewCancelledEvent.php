<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Kyc\Enum\DocumentStatus;

/**
 * Le conseiller annule sa décision sur une pièce (validation, ou refus pas encore signalé au client), avec motif.
 */
final readonly class DocumentReviewCancelledEvent
{
    public function __construct(
        public ComplianceDocument $document,
        public DocumentStatus $cancelledDecision,
        public string $reason,
        public string $advisorName,
    ) {
    }
}
