<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Event\AdequacyReportRevokedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Révoque un rapport d'adéquation validé : il reste archivé et consultable (y compris par le
 * client), mais n'est plus en vigueur, ce qui ouvre la voie à une nouvelle version.
 *
 * L'autorisation relève du voter, vérifié par l'appelant.
 */
final readonly class RevokeAdequacyReportUseCase
{
    public function __construct(
        private AdequacyReportRepositoryInterface $reportRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(AdequacyReport $report, string $reason): void
    {
        $user = $this->userProvider->getUser();
        $reason = trim($reason);

        // Gardes (déjà révoqué / motif vide) portées par l'entité.
        $report->revoke($user, $reason);

        $folder = $report->complianceFolder;
        $folder->saveHistory(
            'Rapport d\'adéquation révoqué',
            sprintf('Version %d — motif : %s', $report->version, $reason),
        );

        $this->transactionManager->transactional(function () use ($report, $folder): void {
            $this->reportRepository->save($report, flush: false);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new AdequacyReportRevokedEvent(
            reportSlugId: $report->slugId,
            folderSlugId: $folder->slugId,
            version: $report->version,
            reason: $reason,
            revokedByName: $user->getFullName(),
        ));
    }
}
