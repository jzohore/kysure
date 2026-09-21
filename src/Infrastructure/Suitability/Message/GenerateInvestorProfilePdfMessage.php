<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Message;

readonly class GenerateInvestorProfilePdfMessage
{
    public function __construct(
        public string $profileSlugId,
    ) {
    }
}
