<?php

declare(strict_types=1);

namespace App\Domain\Billing\Event;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Workspace\Entity\Workspace;

final readonly class AdminSubscriptionSeatsUpdatedEvent
{
    public function __construct(
        public Subscription $subscription,
        public Workspace $workspace,
        public int $previousSeats,
        public int $newSeats,
        public string $actorEmail,
        public string $actorFullName,
    ) {
    }
}
