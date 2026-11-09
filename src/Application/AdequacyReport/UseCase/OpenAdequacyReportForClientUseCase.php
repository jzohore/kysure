<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Event\AdequacyReportOpenedByClientEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\User\Entity\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Remise du rapport d'adéquation via l'espace client : renvoie le chemin du PDF si (et
 * seulement si) le rapport est destiné à CE client, et trace l'ouverture. La propriété du
 * rapport — pas un simple rôle — empêche un client d'ouvrir le rapport d'un autre.
 * Un rapport révoqué reste accessible au client (il lui a été remis), signalé comme remplacé.
 */
final readonly class OpenAdequacyReportForClientUseCase
{
    public function __construct(
        private AdequacyReportRepositoryInterface $reportRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return string|null chemin de stockage du PDF, `null` si introuvable, étranger ou pas encore généré
     */
    public function __invoke(string $reportSlugId, Client $client): ?string
    {
        $report = $this->reportRepository->findBySlugId($reportSlugId);

        if (!$report instanceof AdequacyReport || $report->client !== $client || null === $report->pdfStoragePath) {
            return null;
        }

        $this->eventDispatcher->dispatch(new AdequacyReportOpenedByClientEvent(
            reportSlugId: $report->slugId,
            clientSlugId: $client->slugId,
            version: $report->version,
            contentHash: $report->contentHash,
        ));

        return $report->pdfStoragePath;
    }
}
