<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileComparisonResponse
{
    /**
     * @param list<InvestorProfileComparisonDimensionResponse> $dimensions
     */
    public function __construct(
        public int $oldVersion,
        public int $newVersion,
        public \DateTimeImmutable $oldValidatedAt,
        public \DateTimeImmutable $newValidatedAt,
        public int $oldRetainedLevel,
        public int $newRetainedLevel,
        public string $oldRetainedLabel,
        public string $newRetainedLabel,
        public bool $levelChanged,
        public array $dimensions,
    ) {
    }
}
