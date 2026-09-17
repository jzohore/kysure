<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage]
readonly class DispatchNotifyAssessmentSubmitted
{
    public function __construct(
        private string $recipientEmail,
        private string $clientName,
        private string $reviewUrl,
    ) {
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getReviewUrl(): string
    {
        return $this->reviewUrl;
    }
}
