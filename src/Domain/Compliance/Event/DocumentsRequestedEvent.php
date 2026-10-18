<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Des pièces justificatives sont demandées au client (à l'issue de l'accusé de
 * réception du DER). Scalaires uniquement : rejouable, sérialisable.
 */
final readonly class DocumentsRequestedEvent
{
    /**
     * @param list<string> $documentTypes valeurs de {@see \App\Domain\Compliance\Enum\DocumentType}
     */
    public function __construct(
        private string $folderSlugId,
        private array $documentTypes,
        private \DateTimeImmutable $requestedAt,
    ) {
    }

    public function getFolderSlugId(): string
    {
        return $this->folderSlugId;
    }

    /**
     * @return list<string>
     */
    public function getDocumentTypes(): array
    {
        return $this->documentTypes;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }
}
