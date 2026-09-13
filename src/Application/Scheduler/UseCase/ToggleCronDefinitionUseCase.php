<?php

declare(strict_types=1);

namespace App\Application\Scheduler\UseCase;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Scheduler\Entity\CronDefinition;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;

final readonly class ToggleCronDefinitionUseCase
{
    public function __construct(
        private CronDefinitionRepositoryInterface $definitionRepository,
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    public function __invoke(CronJob $job, bool $enabled): void
    {
        $definition = $this->definitionRepository->findByJob($job) ?? CronDefinition::register($job);

        if ($definition->enabled === $enabled) {
            $this->definitionRepository->save($definition);

            return;
        }

        $enabled ? $definition->enable() : $definition->disable();
        $this->definitionRepository->save($definition);

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: $enabled ? AuditEventType::CRON_DEFINITION_ENABLED : AuditEventType::CRON_DEFINITION_DISABLED,
            payload: ['job' => $job->value],
        ));
    }
}
