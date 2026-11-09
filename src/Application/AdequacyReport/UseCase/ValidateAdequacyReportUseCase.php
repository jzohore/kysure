<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Event\AdequacyReportValidatedEvent;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Database\TransactionManagerInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Valide et fige le rapport d'adéquation d'un dossier (lot 5) à partir des vérifications
 * choisies par le CGP et de sa justification du conseil. Le contenu est recomposé côté
 * serveur depuis la base au moment de la validation (jamais reçu du navigateur).
 *
 * Une seule version en vigueur par dossier : pour la remplacer, la révoquer d'abord.
 *
 * L'autorisation (membre du cabinet + dossier visible + droit de valider des actes) relève
 * du voter, vérifié par l'appelant.
 */
final readonly class ValidateAdequacyReportUseCase
{
    public function __construct(
        private AdequacyReportDraftComposer $composer,
        private AdequacyReportRepositoryInterface $reportRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private TransactionManagerInterface $transactionManager,
        private CurrentUserProvider $userProvider,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param list<string> $checkSlugIds
     *
     * @return string le slug du rapport validé
     */
    public function __invoke(ComplianceFolder $folder, array $checkSlugIds, string $advisorRationale): string
    {
        if ($this->reportRepository->findInForceByFolder($folder) instanceof AdequacyReport) {
            throw new \DomainException('Un rapport d\'adéquation est déjà en vigueur pour ce dossier. Révoquez-le avant d\'en valider une nouvelle version.');
        }

        $draft = $this->composer->compose($folder, $checkSlugIds, $advisorRationale);
        $user = $this->userProvider->getUser();
        $version = $this->reportRepository->findLatestVersionNumber($folder) + 1;

        $report = AdequacyReport::validate(
            folder: $folder,
            investorProfile: $draft->investorProfile,
            checks: $draft->checks,
            validatedBy: $user,
            advisorRationale: $advisorRationale,
            cabinet: $draft->cabinet,
            clientSituation: $draft->clientSituation,
            version: $version,
        );

        $folder->saveHistory(
            'Rapport d\'adéquation validé',
            sprintf('Version %d — %d produit(s) cité(s), validée par %s', $version, $report->checkCount(), $report->validatedByName),
        );

        $this->transactionManager->transactional(function () use ($report, $folder): void {
            $this->reportRepository->save($report, flush: false);
            $this->folderRepository->save($folder);
        });

        $this->eventDispatcher->dispatch(new AdequacyReportValidatedEvent(
            reportSlugId: $report->slugId,
            folderSlugId: $folder->slugId,
            version: $version,
            investorProfileSlugId: $draft->investorProfile->slugId,
            checkCount: $report->checkCount(),
            contentHash: $report->contentHash,
            validatedByName: $report->validatedByName,
        ));

        return $report->slugId;
    }
}
