<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage]
final readonly class SendAssessmentReminderMessage
{
    public function __construct(
        public string $assessmentSlugId,
    ) {
    }
}
