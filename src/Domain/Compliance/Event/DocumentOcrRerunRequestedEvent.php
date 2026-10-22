<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;

/**
 * Émis quand un opérateur relance manuellement l'analyse automatique d'une pièce déjà reçue.
 * Le listener d'infrastructure met alors l'analyse en file ; l'événement ne décide de rien sur la pièce.
 */
final readonly class DocumentOcrRerunRequestedEvent
{
    public function __construct(
        public ComplianceDocument $document,
    ) {
    }
}
