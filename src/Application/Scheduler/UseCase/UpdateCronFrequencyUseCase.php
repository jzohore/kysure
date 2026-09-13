<?php

declare(strict_types=1);

namespace App\Application\Scheduler\UseCase;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Scheduler\Entity\CronDefinition;
use App\Domain\Scheduler\Enum\CronFrequency;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;

final readonly class UpdateCronFrequencyUseCase
{
    public function __construct(
        private CronDefinitionRepositoryInterface $definitionRepository,
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    public function __invoke(CronJob $job, CronFrequency $frequency): void
    {
        $definition = $this->definitionRepository->findByJob($job) ?? CronDefinition::register($job);

        if ($definition->frequency === $frequency) {
            $this->definitionRepository->save($definition);

            return;
        }

        $previousFrequency = $definition->frequency;
        $definition->changeFrequency($frequency);
        $this->definitionRepository->save($definition);

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::CRON_FREQUENCY_CHANGED,
            payload: [
                'job' => $job->value,
                'previous_frequency' => $previousFrequency->value,
                'new_frequency' => $frequency->value,
            ],
        ));
    }
}
