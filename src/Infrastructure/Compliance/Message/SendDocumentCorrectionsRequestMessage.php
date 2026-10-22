<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Message;

/**
 * E-mail groupé au client : pièces refusées à fournir à nouveau (file `async_priority`).
 */
readonly class SendDocumentCorrectionsRequestMessage
{
    /**
     * @param list<string> $documentIds UUID des pièces signalées par CETTE demande
     */
    public function __construct(
        public string $folderId,
        public array $documentIds,
        public string $portalUrl,
    ) {
    }
}
