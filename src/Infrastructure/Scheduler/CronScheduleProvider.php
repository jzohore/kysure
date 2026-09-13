<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Enum\CronTriggerSource;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;
use App\Infrastructure\Scheduler\Message\RunCronJobMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Déclare la planification de toutes les tâches du {@see CronCatalog}. La
 * fréquence de chaque tâche est relue depuis {@see CronDefinition} à chaque
 * recalcul du planning (donc appliquée sans redémarrage du worker) ; à
 * défaut de définition enregistrée, la fréquence par défaut du job
 * s'applique. Le worker existant (`messenger:consume --all`) consomme ce
 * transport comme n'importe quel autre : aucune infra Docker supplémentaire.
 */
#[AsSchedule('cron')]
final readonly class CronScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private CronDefinitionRepositoryInterface $definitionRepository,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $definitionsByJob = [];
        foreach ($this->definitionRepository->findAll() as $definition) {
            $definitionsByJob[$definition->job->value] = $definition;
        }

        $schedule = new Schedule();

        foreach (CronJob::cases() as $job) {
            $frequency = ($definitionsByJob[$job->value] ?? null)->frequency ?? $job->getDefaultFrequency();

            $schedule->add(RecurringMessage::cron(
                $frequency->toCronExpression(),
                new RunCronJobMessage($job, CronTriggerSource::SCHEDULE),
            ));
        }

        return $schedule->stateful($this->cache);
    }
}
