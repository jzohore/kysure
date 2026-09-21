<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileComparisonDimensionResponse
{
    /**
     * @param list<InvestorProfileComparisonAnswerResponse> $answers
     */
    public function __construct(
        public string $label,
        public array $answers,
    ) {
    }
}
