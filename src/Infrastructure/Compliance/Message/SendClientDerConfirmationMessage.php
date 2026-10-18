<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage]
readonly class SendClientDerConfirmationMessage
{
    public function __construct(
        private string $portalUrl,
        private string $folderId,
    ) {
    }

    public function getPortalUrl(): string
    {
        return $this->portalUrl;
    }

    public function getFolderId(): string
    {
        return $this->folderId;
    }
}
