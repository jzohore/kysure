<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler\Message;

use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Enum\CronTriggerSource;

final readonly class RunCronJobMessage
{
    public function __construct(
        public CronJob $job,
        public CronTriggerSource $triggeredBy,
    ) {
    }
}
