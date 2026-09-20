<?php

declare(strict_types=1);

namespace App\Application\Suitability\DTO\Response;

readonly class InvestorProfileSynthesisAnswerResponse
{
    public function __construct(
        public string $questionLabel,
        public string $answerLabel,
    ) {
    }
}
