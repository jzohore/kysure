<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Port\OcrProviderInterface;

/**
 * Choisit le moteur d'extraction selon `OCR_MODE` :
 * `real` (AWS Textract), `mindee`, `local` (service Python), `hybrid` (local + Textract), sinon le fake.
 */
readonly class OcrFactory
{
    public function __construct(
        private MindeeProvider $mindeeProvider,
        private FakeOcrProvider $fakeOcrProvider,
        private AmazonTextractProvider $amazonTextractProvider,
        private LocalOcrProvider $localOcrProvider,
        private HybridOcrProvider $hybridOcrProvider,
    ) {
    }

    public function create(string $mode): OcrProviderInterface
    {
        return match ($mode) {
            'real' => $this->amazonTextractProvider,
            'mindee' => $this->mindeeProvider,
            'local' => $this->localOcrProvider,
            'hybrid' => $this->hybridOcrProvider,
            default => $this->fakeOcrProvider,
        };
    }
}
