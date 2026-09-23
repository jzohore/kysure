<?php

declare(strict_types=1);

namespace App\Application\Feedback\UseCase;

use App\Domain\Feedback\Entity\Feedback;
use App\Domain\Feedback\Repository\FeedbackRepositoryInterface;
use App\Domain\User\Entity\User;

readonly class SubmitFeedbackUseCase
{
    public function __construct(
        private FeedbackRepositoryInterface $feedbackRepository,
    ) {
    }

    public function __invoke(User $submittedBy, string $message, string $pageUrl, ?string $pageTitle): Feedback
    {
        $feedback = Feedback::submit($submittedBy, $message, $pageUrl, $pageTitle);

        $this->feedbackRepository->save($feedback);

        return $feedback;
    }
}
