<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Message;

/**
 * Porte l'empreinte du contenu validé : le handler ne génère le PDF que si le rapport stocké
 * correspond toujours exactement à ce qui a été validé.
 */
readonly class GenerateAdequacyReportPdfMessage
{
    public function __construct(
        public string $reportSlugId,
        public string $contentHash,
    ) {
    }
}
