<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileSynthesisDimensionResponse
{
    /**
     * @param list<InvestorProfileSynthesisAnswerResponse> $answers
     */
    public function __construct(
        public string $label,
        public array $answers,
    ) {
    }
}
