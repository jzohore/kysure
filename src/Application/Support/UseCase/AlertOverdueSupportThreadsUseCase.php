<?php

declare(strict_types=1);

namespace App\Application\Support\UseCase;

use App\Domain\Support\Port\SupportSlaBreachNotifierInterface;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Alerte l'équipe KYSURE sur Slack (lot P4) dès qu'un ticket support dépasse son échéance de
 * première réponse (SLA) sans avoir été traité — jusqu'ici, {@see \App\Domain\Support\Entity\SupportThread::isOverdue()}
 * n'était utilisé que pour l'affichage dans la liste admin, personne n'était notifié activement.
 */
final readonly class AlertOverdueSupportThreadsUseCase
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private SupportSlaBreachNotifierInterface $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(): int
    {
        $overdueThreads = $this->threadRepository->findOverdueOpenThreadsNeedingAlert();
        $alerted = 0;

        foreach ($overdueThreads as $thread) {
            try {
                // L'échec d'un envoi Slack (canal mal configuré, bot non invité...) ne doit
                // jamais empêcher l'alerte des autres tickets en dépassement du même passage —
                // le ticket en échec sera retenté au prochain cycle (slaBreachAlertSent reste
                // à false), sans bloquer les autres.
                $this->notifier->alert($thread);
                $thread->markSlaBreachAlertAsSent();
                $this->threadRepository->save($thread);
                ++$alerted;
            } catch (\Throwable $e) {
                $this->logger->error('Échec de l\'alerte Slack de dépassement SLA pour un ticket support.', [
                    'thread_slug_id' => $thread->slugId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $alerted;
    }
}
