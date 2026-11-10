<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Event;

/**
 * Le PDF du rapport d'adéquation est stocké et mis à disposition du client dans son espace :
 * c'est la remise sur support durable, datée, que le client doit être averti par e-mail.
 * Émis une seule fois par rapport (garde portée par l'entité).
 */
final readonly class AdequacyReportMadeAvailableEvent
{
    public function __construct(
        public string $reportSlugId,
        public string $folderSlugId,
        public int $version,
        public string $contentHash,
        public string $pdfSha256,
        public \DateTimeImmutable $madeAvailableAt,
    ) {
    }
}
