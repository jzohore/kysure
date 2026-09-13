<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Application\Scheduler\UseCase\ToggleCronDefinitionUseCase;
use App\Application\Scheduler\UseCase\UpdateCronFrequencyUseCase;
use App\Domain\Scheduler\Entity\CronExecutionLog;
use App\Domain\Scheduler\Enum\CronExecutionStatus;
use App\Domain\Scheduler\Enum\CronFrequency;
use App\Domain\Scheduler\Enum\CronJob;
use App\Domain\Scheduler\Enum\CronTriggerSource;
use App\Domain\Scheduler\Repository\CronDefinitionRepositoryInterface;
use App\Domain\Scheduler\Repository\CronExecutionLogRepositoryInterface;
use App\Infrastructure\Scheduler\Message\RunCronJobMessage;
use App\Infrastructure\Shared\Component\LiveFlashTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Vue back-office des tâches planifiées : historique de dernière exécution,
 * activation/désactivation, fréquence (liste fermée), déclenchement manuel
 * (envoyé en asynchrone — jamais exécuté dans la requête HTTP).
 */
#[AsLiveComponent(
    name: 'AdminCronListComponent',
    template: 'components/Admin/Cron/AdminCronListComponent.html.twig',
)]
final class AdminCronListComponent
{
    use DefaultActionTrait;
    use LiveFlashTrait;

    /**
     * Sélection en cours dans les listes déroulantes, par job (clé = CronJob::value).
     * Tant qu'aucune sélection n'a été faite, la fréquence actuellement
     * enregistrée fait foi (cf. template).
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $pendingFrequency = [];

    public function __construct(
        private readonly CronDefinitionRepositoryInterface $definitionRepository,
        private readonly CronExecutionLogRepositoryInterface $executionLogRepository,
        private readonly ToggleCronDefinitionUseCase $toggleCronDefinitionUseCase,
        private readonly UpdateCronFrequencyUseCase $updateCronFrequencyUseCase,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{job: CronJob, enabled: bool, frequency: CronFrequency, lastRun: ?CronExecutionLog}>
     */
    public function getRows(): array
    {
        return array_map(
            function (CronJob $job): array {
                $definition = $this->definitionRepository->findByJob($job);

                return [
                    'job' => $job,
                    'enabled' => $definition->enabled ?? true,
                    'frequency' => $definition->frequency ?? $job->getDefaultFrequency(),
                    'lastRun' => $this->executionLogRepository->findLatestByJob($job),
                ];
            },
            CronJob::cases(),
        );
    }

    /**
     * @return list<CronFrequency>
     */
    public function getFrequencyOptions(): array
    {
        return CronFrequency::cases();
    }

    /**
     * Tant qu'une tâche est en cours, le composant se rafraîchit tout seul
     * (cf. `data-poll` dans le template) pour refléter le résultat sans
     * rechargement manuel de la page.
     */
    public function isAnyRunning(): bool
    {
        return array_any($this->getRows(), static fn (array $row): bool => CronExecutionStatus::RUNNING === $row['lastRun']?->status);
    }

    #[LiveAction]
    public function toggle(#[LiveArg] string $job): void
    {
        $cronJob = CronJob::from($job);
        $currentlyEnabled = $this->definitionRepository->findByJob($cronJob)->enabled ?? true;

        try {
            ($this->toggleCronDefinitionUseCase)($cronJob, !$currentlyEnabled);
            $this->addLiveFlash('success', sprintf(
                'Tâche « %s » %s.',
                $cronJob->getLabel(),
                $currentlyEnabled ? 'désactivée' : 'activée',
            ));
        } catch (\Throwable) {
            $this->logger->error('Crash lors du changement d\'état d\'une tâche planifiée.', ['job' => $job]);
            $this->addLiveFlash('error', 'Erreur système lors du changement d\'état.');
        }
    }

    #[LiveAction]
    public function saveFrequency(#[LiveArg] string $job): void
    {
        $cronJob = CronJob::from($job);
        $selected = $this->pendingFrequency[$job] ?? null;
        $frequency = null !== $selected ? CronFrequency::tryFrom($selected) : null;

        if (!$frequency instanceof CronFrequency) {
            $this->addLiveFlash('error', 'Fréquence invalide.');

            return;
        }

        try {
            ($this->updateCronFrequencyUseCase)($cronJob, $frequency);
            $this->addLiveFlash('success', sprintf(
                'Fréquence de « %s » mise à jour : %s.',
                $cronJob->getLabel(),
                $frequency->getLabel(),
            ));
        } catch (\Throwable) {
            $this->logger->error('Crash lors du changement de fréquence d\'une tâche planifiée.', ['job' => $job]);
            $this->addLiveFlash('error', 'Erreur système lors du changement de fréquence.');
        }
    }

    #[LiveAction]
    public function runNow(#[LiveArg] string $job): void
    {
        $cronJob = CronJob::from($job);

        try {
            $this->messageBus->dispatch(new RunCronJobMessage($cronJob, CronTriggerSource::MANUAL));
            $this->addLiveFlash('success', sprintf(
                'Tâche « %s » lancée : le résultat apparaîtra dans l\'historique sous peu.',
                $cronJob->getLabel(),
            ));
        } catch (\Throwable) {
            $this->logger->error('Crash lors du déclenchement manuel d\'une tâche planifiée.', ['job' => $job]);
            $this->addLiveFlash('error', 'Erreur système lors du déclenchement.');
        }
    }
}
