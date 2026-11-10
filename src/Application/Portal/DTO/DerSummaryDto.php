<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

/**
 * Récapitulatif de l'accusé de réception du DER pour l'espace client :
 * ce que le client a déjà fait (accuser réception) et ce qu'il peut récupérer
 * (le DER lui-même, l'attestation).
 */
readonly class DerSummaryDto
{
    public function __construct(
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public \DateTimeImmutable $acknowledgedAt,
        public string $pdfSha256,
        /** Chemin S3 du DER, à passer au filtre `private_url` pour un lien temporaire. */
        public string $pdfStoragePath,
        /** Chemin S3 de l'attestation d'accusé de réception, ou null si pas encore générée. */
        public ?string $certificateStoragePath = null,
    ) {
    }
}
