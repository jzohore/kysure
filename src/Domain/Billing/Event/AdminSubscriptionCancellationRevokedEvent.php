<?php

declare(strict_types=1);

namespace App\Domain\Billing\Event;

use App\Domain\Billing\Entity\Subscription;
use App\Domain\Workspace\Entity\Workspace;

final readonly class AdminSubscriptionCancellationRevokedEvent
{
    public function __construct(
        public Subscription $subscription,
        public Workspace $workspace,
        public string $actorEmail,
        public string $actorFullName,
    ) {
    }
}
