<?php

declare(strict_types=1);

namespace App\Domain\Support\Event;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\User\Entity\Admin;

final readonly class SupportThreadAssignedEvent
{
    public function __construct(
        public SupportThread $thread,
        public ?Admin $assignedTo,
    ) {
    }
}
