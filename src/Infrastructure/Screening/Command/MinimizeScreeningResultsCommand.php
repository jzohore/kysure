<?php

declare(strict_types=1);

namespace App\Infrastructure\Screening\Command;

use App\Domain\Screening\Entity\ScreeningAudit;
use App\Domain\Screening\Repository\ScreeningAuditRepositoryInterface;
use Psr\Log\LoggerInterface;

use function Symfony\Component\Clock\now;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Minimisation RGPD (à planifier via cron) : retire le `raw_data` (dump Open
 * Sanctions complet, PII de tiers homonymes) des résultats de screening
 * au-delà de {@see ScreeningAudit::RESULTS_RETENTION_DAYS} jours. Le résumé
 * exploitable et une empreinte d'intégrité des résultats d'origine sont
 * conservés.
 */
#[AsCommand(
    name: 'app:rgpd:minimize-screening-results',
    description: 'Retire les données brutes de tiers des résultats de screening périmés.',
)]
final readonly class MinimizeScreeningResultsCommand
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private ScreeningAuditRepositoryInterface $auditRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Affiche ce qui serait minimisé sans rien modifier.')]
        bool $dryRun = false,
        #[Option(description: 'Nombre maximum d\'audits traités sur cette exécution.')]
        int $limit = 1000,
    ): int {
        $threshold = now()->modify(sprintf('-%d days', ScreeningAudit::RESULTS_RETENTION_DAYS));

        $limit = max(1, $limit);

        if ($dryRun) {
            $count = count($this->auditRepository->findMinimizableResults($threshold, $limit));
        } else {
            $count = $this->minimizeInBatches($threshold, $limit);
        }

        if (0 === $count) {
            $io->success(sprintf('Aucun résultat de screening à minimiser (seuil : %s).', $threshold->format('d/m/Y')));

            return Command::SUCCESS;
        }

        if (!$dryRun) {
            $this->logger->info('Minimisation RGPD des résultats de screening.', [
                'minimized' => $count,
                'threshold' => $threshold->format(\DateTimeInterface::ATOM),
            ]);
        }

        $io->success(sprintf(
            '%s%d audit(s) de screening : données brutes de tiers %s (créés avant le %s).',
            $dryRun ? '[DRY-RUN] ' : '',
            $count,
            $dryRun ? 'à retirer' : 'retirées',
            $threshold->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }

    /**
     * 🛡️ Traitement par lots : un flush par lot (et non par entité) et détachement après chaque lot,
     * pour que la mémoire reste constante. Un audit minimisé sort de la requête (resultsMinimizedAt),
     * donc relire « les N premiers » à chaque tour est sûr et rejouable.
     */
    private function minimizeInBatches(\DateTimeImmutable $threshold, int $limit): int
    {
        $processed = 0;

        while ($processed < $limit) {
            $batch = $this->auditRepository->findMinimizableResults($threshold, min(self::BATCH_SIZE, $limit - $processed));

            if ([] === $batch) {
                break;
            }

            foreach ($batch as $audit) {
                $audit->minimizeResults();
            }

            $this->auditRepository->saveBatchAndDetach($batch);
            $processed += count($batch);
        }

        return $processed;
    }
}
