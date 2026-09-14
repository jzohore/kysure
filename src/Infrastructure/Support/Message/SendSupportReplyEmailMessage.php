<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Message;

final readonly class SendSupportReplyEmailMessage
{
    public function __construct(
        public string $threadId,
        public string $ticketUrl,
    ) {
    }
}
