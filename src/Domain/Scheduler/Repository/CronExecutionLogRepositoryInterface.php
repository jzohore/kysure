<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Repository;

use App\Domain\Scheduler\Entity\CronExecutionLog;
use App\Domain\Scheduler\Enum\CronJob;

interface CronExecutionLogRepositoryInterface
{
    public function save(CronExecutionLog $log): void;

    public function findLatestByJob(CronJob $job): ?CronExecutionLog;

    /**
     * @return list<CronExecutionLog>
     */
    public function findRecentByJob(CronJob $job, int $limit = 10): array;
}
