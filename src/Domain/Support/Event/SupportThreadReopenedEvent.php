<?php

declare(strict_types=1);

namespace App\Domain\Support\Event;

use App\Domain\Support\Entity\SupportThread;

final readonly class SupportThreadReopenedEvent
{
    public function __construct(
        public SupportThread $thread,
    ) {
    }
}
