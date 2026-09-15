<?php

declare(strict_types=1);

namespace App\Domain\Support\Event;

use App\Domain\Support\Entity\SupportThread;

final readonly class SupportThreadCreatedEvent
{
    public function __construct(
        public SupportThread $thread,
    ) {
    }
}
