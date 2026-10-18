<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Message;

/**
 * Demande d'analyse automatique d'une pièce déposée (file `async_heavy`).
 *
 * Porte l'UUID (clé primaire) du {@see \App\Domain\Compliance\Entity\ComplianceDocument}, pas son slug :
 * c'est ce que résout `ComplianceDocumentRepositoryInterface::findById()`.
 */
readonly class ProcessOcrMessage
{
    public function __construct(
        public string $documentId,
    ) {
    }
}
