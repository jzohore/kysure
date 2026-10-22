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
    public function extractData(DocumentType $type, string $filePath): array
    {
        return $this->client->extract($type, $filePath);
    }
}
