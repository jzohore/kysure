<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

/**
 * Rapport d'adéquation remis au client, tel qu'affiché dans son espace (lot 5).
 */
readonly class AdequacyReportSummaryDto
{
    public function __construct(
        public string $slugId,
        public int $version,
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public \DateTimeImmutable $validatedAt,
        public string $contentHash,
        /** Faux pour une version révoquée : elle reste consultable, signalée comme remplacée. */
        public bool $inForce,
    ) {
    }
}
