<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Event;

/**
 * Le CGP a validé et figé un rapport d'adéquation : il devient opposable et sera remis au
 * client (PDF généré en asynchrone).
 */
final readonly class AdequacyReportValidatedEvent
{
    public function __construct(
        public string $reportSlugId,
        public string $folderSlugId,
        public int $version,
        public string $investorProfileSlugId,
        public int $checkCount,
        public string $contentHash,
        public string $validatedByName,
    ) {
    }
}
