<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;

/**
 * Émis quand l'analyse automatique d'une pièce a définitivement échoué (retries épuisés) :
 * la pièce est signalée au CGP pour vérification manuelle. N'a rien d'une décision sur la pièce.
 */
final readonly class DocumentOcrUnavailableEvent
{
    public function __construct(
        public ComplianceDocument $document,
    ) {
    }
}
