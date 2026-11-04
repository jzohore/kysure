<?php

declare(strict_types=1);

namespace App\Infrastructure\KYC\Message;

readonly class ProcessAndStoreKycDocumentMessage
{
    public function __construct(
        public string $documentId,
        public string $folderId,
        public string $localTempPath,
        public string $mimeType,
        public string $originalName,
        public int $size,
        public ?string $oldStoragePath = null,
        /** Verso d'une pièce à deux faces (le recto reste le fichier principal). */
        public bool $isBackSide = false,
    ) {
    }
}
