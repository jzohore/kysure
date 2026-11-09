<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Event;

/**
 * Un rapport d'adéquation a été révoqué (il reste archivé et consultable, une nouvelle
 * version pourra être validée).
 */
final readonly class AdequacyReportRevokedEvent
{
    public function __construct(
        public string $reportSlugId,
        public string $folderSlugId,
        public int $version,
        public string $reason,
        public string $revokedByName,
    ) {
    }
}
