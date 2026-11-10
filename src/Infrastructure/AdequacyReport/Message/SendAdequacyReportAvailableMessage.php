<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Message;

readonly class SendAdequacyReportAvailableMessage
{
    public function __construct(
        public string $clientEmail,
        public string $workspaceName,
        public ?string $workspaceContactEmail,
        public string $portalUrl,
        public int $version,
    ) {
    }
}
