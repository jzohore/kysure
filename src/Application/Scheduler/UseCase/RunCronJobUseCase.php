<?php

declare(strict_types=1);

namespace App\Application\Scheduler\UseCase;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Scheduler\Entity\CronDefinition;
use App\Domain\Scheduler\Entity\CronExecutionLog;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Enum\CronTriggerSource;
use App\Domain\Scheduler\Gateway\ConsoleCommandRunnerInterface;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;
use App\Domain\Scheduler\Repository\CronExecutionLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Exécute une tâche planifiée (déclenchement automatique ou manuel), en
 * garantissant : pas d'exécution si désactivée depuis le back-office (sauf
 * déclenchement manuel, qui est une décision explicite prenant le pas dessus),
 * pas de double exécution concurrente (verrou), et un historique inaltérable
 * du résultat.
 */
final readonly class RunCronJobUseCase
{
    private const float LOCK_TTL_SECONDS = 600.0;

    public function __construct(
        private CronDefinitionRepositoryInterface $definitionRepository,
        private CronExecutionLogRepositoryInterface $executionLogRepository,
        private ConsoleCommandRunnerInterface $commandRunner,
        private AuditLogRepositoryInterface $auditLogRepository,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CronJob $job, CronTriggerSource $triggeredBy): void
    {
        $definition = $this->definitionRepository->findByJob($job) ?? CronDefinition::register($job);

        if (!$definition->enabled && CronTriggerSource::SCHEDULE === $triggeredBy) {
            $this->recordSkipped($job, $triggeredBy, 'Tâche désactivée depuis le back-office.');

            return;
        }

        $this->definitionRepository->save($definition);

        $lock = $this->lockFactory->createLock('cron.' . $job->value, self::LOCK_TTL_SECONDS);

        if (!$lock->acquire()) {
            $this->recordSkipped($job, $triggeredBy, 'Une exécution de cette tâche est déjà en cours.');

            return;
        }

        $log = CronExecutionLog::start($job, $triggeredBy);
        $this->executionLogRepository->save($log);

        try {
            $result = $this->commandRunner->run($job);

            if ($result->isSuccessful()) {
                $log->markSuccess();
            } else {
                $log->markFailure($result->output);
                $this->logger->error('Échec de l\'exécution d\'une tâche planifiée.', [
                    'job' => $job->value,
                    'exitCode' => $result->exitCode,
                ]);
            }
        } catch (\Throwable $e) {
            $log->markFailure($e->getMessage());
            $this->logger->error('Crash lors de l\'exécution d\'une tâche planifiée.', [
                'job' => $job->value,
                'exception' => $e->getMessage(),
            ]);
        } finally {
            $this->executionLogRepository->save($log);

            try {
                $lock->release();
            } catch (\Throwable) {
                // Verrou déjà expiré (dépassement du TTL) : sans conséquence, l'historique fait foi.
            }
        }

        if (CronTriggerSource::MANUAL === $triggeredBy) {
            $this->auditLogRepository->save(AuditLog::initiate(
                eventName: AuditEventType::CRON_JOB_TRIGGERED_MANUALLY,
                payload: ['job' => $job->value, 'status' => $log->status->value],
            ));
        }
    }

    private function recordSkipped(CronJob $job, CronTriggerSource $triggeredBy, string $reason): void
    {
        $log = CronExecutionLog::start($job, $triggeredBy);
        $log->markSkipped($reason);
        $this->executionLogRepository->save($log);
    }
}
