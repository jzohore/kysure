<?php

declare(strict_types=1);

namespace App\Domain\Port;

use App\Domain\Compliance\Enum\DocumentType;

interface OcrProviderInterface
{
    /**
     * @param string      $filePath     Le chemin local (temporaire) du document (le recto pour une pièce à deux faces)
     * @param string|null $backFilePath Le verso, quand la pièce en a un ({@see DocumentType::hasBackSide()}) et qu'il est déposé
     *
     * @return array<string, mixed> Les données extraites normalisées
     *
     * @throws \DomainException Si le document n'est pas supporté ou illisible
     */
    public function extractData(DocumentType $type, string $filePath, ?string $backFilePath = null): array;
}
