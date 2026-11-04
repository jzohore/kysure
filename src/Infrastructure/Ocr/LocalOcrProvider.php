<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;

/**
 * Extraction par le service Python auto-hébergé `ocr-service/` (Tesseract, MRZ à clés ICAO, PDF natifs).
 * Transport, sémantique d'erreur et confidentialité des logs : voir {@see OcrServiceClient}.
 */
readonly class LocalOcrProvider implements OcrProviderInterface
{
    public function __construct(
        private OcrServiceClient $client,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractData(DocumentType $type, string $filePath, ?string $backFilePath = null): array
    {
        if (null === $backFilePath) {
            return $this->client->extract($type, $filePath);
        }

        // Deux fichiers : la MRZ n'est que sur le verso, les champs lisibles peuvent être sur l'une ou l'autre face.
        // Une face illisible (422) ne condamne pas l'autre ; une panne technique remonte, elle, pour que Messenger réessaie.
        $front = $this->extractOrNull($type, $filePath);
        $back = $this->extractOrNull($type, $backFilePath);

        if (null === $front && null === $back) {
            throw new \DomainException('Ni le recto ni le verso n\'ont pu être lus.');
        }

        $merged = $front ?? [];
        foreach ($back ?? [] as $field => $value) {
            // Le recto fait foi pour ce qu'il a lu ; le verso comble les manques (typiquement la MRZ).
            if (empty($merged[$field])) {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>|null null = document illisible (\DomainException du contrat du port)
     */
    private function extractOrNull(DocumentType $type, string $filePath): ?array
    {
        try {
            return $this->client->extract($type, $filePath);
        } catch (\DomainException) {
            return null;
        }
    }
}
