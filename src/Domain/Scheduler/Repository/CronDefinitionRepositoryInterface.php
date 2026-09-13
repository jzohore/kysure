<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\Repository;

use App\Domain\Scheduler\Entity\CronDefinition;
use App\Domain\Scheduler\Enum\CronJob;

interface CronDefinitionRepositoryInterface
{
    public function save(CronDefinition $definition): void;

    public function findByJob(CronJob $job): ?CronDefinition;

    /**
     * @return list<CronDefinition>
     */
    public function findAll(): array;
}
