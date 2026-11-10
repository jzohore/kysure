<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Kyc\Enum\DocumentStatus;

readonly class DocumentItemDto
{
    public function __construct(
        public string $id,
        public string $name, // Ex: "Carte d'identité"
        public DocumentStatus $status, // Ex: PENDING, VALID, REJECTED
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public ?\DateTimeImmutable $uploadedAt = null,
        public ?string $rejectionReason = null,
        /** Chemin S3 de la pièce transmise, à passer à `private_url` (null tant que rien n'est déposé). */
        public ?string $storagePath = null,
    ) {
    }
}
