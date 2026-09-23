<?php

declare(strict_types=1);

namespace App\Application\Feedback\UseCase;

use App\Domain\Feedback\Entity\Feedback;
use App\Domain\Feedback\Repository\FeedbackRepositoryInterface;

readonly class ListFeedbackUseCase
{
    public function __construct(
        private FeedbackRepositoryInterface $feedbackRepository,
    ) {
    }

    /**
     * @return list<Feedback>
     */
    public function __invoke(): array
    {
        return $this->feedbackRepository->findAllNewestFirst();
    }
}
