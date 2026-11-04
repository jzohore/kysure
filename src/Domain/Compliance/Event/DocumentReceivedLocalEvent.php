<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use Symfony\Contracts\EventDispatcher\Event;

class DocumentReceivedLocalEvent extends Event
{
    public function __construct(
        public readonly ComplianceDocument $complianceDocument,
        public readonly ComplianceFolder $complianceFolder,
        public readonly string $localTempPath,
        public readonly string $mimeType,
        public readonly string $originalName,
        public readonly int $size,
        public readonly ?string $oldStoragePath = null,
        public readonly bool $isBackSide = false,
    ) {
    }
}
