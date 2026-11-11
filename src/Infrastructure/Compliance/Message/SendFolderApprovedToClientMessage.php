<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Message;

readonly class SendFolderApprovedToClientMessage
{
    public function __construct(
        public string $clientEmail,
        public string $workspaceName,
        public ?string $workspaceContactEmail,
        public string $folderReference,
        public string $portalUrl,
    ) {
    }
}
