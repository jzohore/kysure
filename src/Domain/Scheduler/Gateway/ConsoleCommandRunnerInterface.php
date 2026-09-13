<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Gateway;

use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\ValueObject\CommandRunResult;

interface ConsoleCommandRunnerInterface
{
    public function run(CronJob $job): CommandRunResult;
}
