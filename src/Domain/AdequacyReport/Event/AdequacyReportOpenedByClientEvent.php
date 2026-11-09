<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Event;

/**
 * Le client a ouvert le PDF de son rapport d'adéquation depuis son espace : trace de la remise
 * effective du document.
 */
final readonly class AdequacyReportOpenedByClientEvent
{
    public function __construct(
        public string $reportSlugId,
        public string $clientSlugId,
        public int $version,
        public string $contentHash,
    ) {
    }
}
