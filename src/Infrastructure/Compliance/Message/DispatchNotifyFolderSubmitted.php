<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Message;

readonly class DispatchNotifyFolderSubmitted
{
    public function __construct(
        public string $recipientEmail,
        public string $clientName,
        public string $folderReference,
        public string $reviewUrl,
    ) {
    }
}
