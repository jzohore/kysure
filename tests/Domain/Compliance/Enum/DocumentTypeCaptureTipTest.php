<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance\Enum;

use App\Domain\Compliance\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeCaptureTipTest extends TestCase
{
    public function testEveryTypeReadByTheAutomaticAnalysisHasACaptureTip(): void
    {
        foreach (DocumentType::cases() as $type) {
            if ($type->supportsOcr()) {
                self::assertNotNull($type->getCaptureTip(), sprintf('%s est lu par l\'OCR : une consigne de prise de vue est attendue.', $type->name));
            }
        }
    }

    public function testDerNeverGetsACaptureTipBecauseTheClientDoesNotUploadIt(): void
    {
        self::assertNull(DocumentType::DER->getCaptureTip());
    }
}
