<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileComparisonAnswerResponse
{
    public function __construct(
        public string $questionLabel,
        public string $oldAnswerLabel,
        public string $newAnswerLabel,
        public bool $changed,
    ) {
    }
}
