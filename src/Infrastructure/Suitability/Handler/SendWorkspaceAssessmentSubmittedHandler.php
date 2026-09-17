<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Handler;

use App\Infrastructure\Notification\Email\Suitability\WorkspaceInvestorProfileSubmittedEmail;
use App\Infrastructure\Suitability\Message\DispatchNotifyAssessmentSubmitted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendWorkspaceAssessmentSubmittedHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(DispatchNotifyAssessmentSubmitted $message): void
    {
        $email = new WorkspaceInvestorProfileSubmittedEmail(
            email: $message->getRecipientEmail(),
            clientName: $message->getClientName(),
            reviewUrl: $message->getReviewUrl(),
        );

        $this->mailer->send($email);
    }
}
