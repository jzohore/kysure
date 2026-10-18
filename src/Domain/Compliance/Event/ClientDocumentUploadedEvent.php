<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le client a déposé une pièce justificative. Scalaires uniquement : rejouable, sérialisable.
 * L'empreinte SHA-256 est celle du fichier tel que reçu (avant tout traitement d'image).
 */
final readonly class ClientDocumentUploadedEvent
{
    public function __construct(
        private string $documentId,
        private string $folderSlugId,
        private string $documentType,
        private string $mimeType,
        private int $size,
        private string $sha256,
        private \DateTimeImmutable $uploadedAt,
    ) {
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getFolderSlugId(): string
    {
        return $this->folderSlugId;
    }

    public function getDocumentType(): string
    {
        return $this->documentType;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
