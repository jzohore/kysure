<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Application\Compliance\DTO\Response\FolderChecklistItem;
use App\Application\Compliance\DTO\Response\FolderChecklistResponse;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\ValueObject\DerStatement;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;

/**
 * Checklist de pilotage d'un dossier (lot 8, issue #35), en lecture seule : chaque étape est
 * DÉRIVÉE de l'état des entités existantes (DER, pièces, fiche patrimoniale, profil,
 * vérifications, rapport, décision), sans rien stocker ni toucher à `ComplianceFolderStatus`.
 *
 * Pour une personne morale, seules les étapes DER, pièces et décision s'appliquent (pas de
 * conseil en investissement documenté dans KYSURE pour un dossier KYB).
 */
final readonly class GetFolderChecklistUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private ClientFileRepositoryInterface $clientFileRepository,
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private ProductAdequacyCheckRepositoryInterface $checkRepository,
        private AdequacyReportRepositoryInterface $reportRepository,
    ) {
    }

    public function __invoke(ComplianceFolder $folder): FolderChecklistResponse
    {
        $items = [$this->derItem($folder), $this->documentsItem($folder)];

        if ($folder instanceof IndividualFolder) {
            $profile = $folder->client instanceof Client
                ? $this->profileRepository->findInForceByClient($folder->client, $folder->workspace)
                : null;

            $items[] = $this->clientFileItem($folder);
            $items[] = $this->profileItem($profile);
            $items[] = $this->checksItem($folder, $profile);
            $items[] = $this->reportItem($folder);
        }

        $items[] = $this->decisionItem($folder);

        return new FolderChecklistResponse($items);
    }

    private function derItem(ComplianceFolder $folder): FolderChecklistItem
    {
        $der = $this->documentRepository->findDerByFolder($folder);
        $acknowledgement = $der instanceof ComplianceDocument ? $der->acknowledgementInForce() : null;

        if (!$acknowledgement instanceof DerAcknowledgement) {
            return new FolderChecklistItem('DER accusé par le client', false, 'Le client n\'a pas encore accusé réception du document d\'entrée en relation.');
        }

        // Un accusé antérieur à l'attestation 2026-11-10 ne vaut pas accord pour la remise électronique.
        if (!DerStatement::coversElectronicDelivery($acknowledgement->statementVersion)) {
            return new FolderChecklistItem('DER accusé par le client', false, sprintf('Accusé le %s sur une ancienne version : renvoyez le DER pour recueillir l\'accord de remise électronique.', $acknowledgement->acknowledgedAt->format('d/m/Y')));
        }

        return new FolderChecklistItem('DER accusé par le client', true, sprintf('Accusé le %s, remise électronique acceptée.', $acknowledgement->acknowledgedAt->format('d/m/Y')));
    }

    private function documentsItem(ComplianceFolder $folder): FolderChecklistItem
    {
        // Pièces attendues du dossier (obligatoires ou demandées), hors DER qui a sa propre étape.
        // Lues sur le dossier lui-même : le dépôt ne renvoie que les pièces déjà envoyées au client.
        $requested = array_values(array_filter(
            $folder->documents->toArray(),
            static fn (ComplianceDocument $document): bool => $document->isRequestedFromClient(),
        ));
        $valid = \count(array_filter($requested, static fn (ComplianceDocument $document): bool => DocumentStatus::VALID === $document->status));
        $total = \count($requested);

        if (0 === $total) {
            return new FolderChecklistItem('Pièces justificatives validées', false, 'Aucune pièce n\'est encore attendue dans ce dossier.');
        }

        return new FolderChecklistItem('Pièces justificatives validées', $valid === $total, sprintf('%d pièce%s validée%s sur %d.', $valid, $valid > 1 ? 's' : '', $valid > 1 ? 's' : '', $total));
    }

    private function clientFileItem(ComplianceFolder $folder): FolderChecklistItem
    {
        $clientFile = $this->clientFileRepository->findByComplianceFolder($folder);
        $complete = $clientFile instanceof ClientFile && $clientFile->isComplete();

        return new FolderChecklistItem(
            'Fiche patrimoniale complète',
            $complete,
            $complete ? 'État civil, situation patrimoniale et objectifs renseignés.' : 'État civil, situation patrimoniale ou objectifs encore à renseigner.',
        );
    }

    private function profileItem(?ValidatedInvestorProfile $profile): FolderChecklistItem
    {
        if (!$profile instanceof ValidatedInvestorProfile) {
            return new FolderChecklistItem('Profil investisseur validé', false, 'Aucun profil investisseur validé n\'est en vigueur pour ce client.', requiredForApproval: true);
        }

        return new FolderChecklistItem('Profil investisseur validé', true, sprintf('Version %d, validée le %s.', $profile->version, $profile->validatedAt->format('d/m/Y')), requiredForApproval: true);
    }

    private function checksItem(ComplianceFolder $folder, ?ValidatedInvestorProfile $profile): FolderChecklistItem
    {
        // Citable dans un rapport : faite sur le profil en vigueur, avec des frais figés.
        $citable = $profile instanceof ValidatedInvestorProfile ? \count(array_filter(
            $this->checkRepository->findByFolder($folder, 50),
            static fn (ProductAdequacyCheck $check): bool => $check->investorProfile === $profile && $check->costs() instanceof \App\Domain\AdequacyReport\ValueObject\CostBreakdown,
        )) : 0;

        return new FolderChecklistItem(
            'Produit vérifié',
            $citable > 0,
            $citable > 0 ? sprintf('%d vérification%s d\'adéquation sur le profil en vigueur.', $citable, $citable > 1 ? 's' : '') : 'Aucune vérification d\'adéquation sur le profil en vigueur.',
            '#heading-adequacy',
        );
    }

    private function reportItem(ComplianceFolder $folder): FolderChecklistItem
    {
        $report = $this->reportRepository->findInForceByFolder($folder);

        if (!$report instanceof AdequacyReport) {
            return new FolderChecklistItem('Rapport d\'adéquation validé et remis', false, 'Aucun rapport d\'adéquation en vigueur.', '#heading-adequacy-report', true);
        }

        if ($report->isBasedOnRevokedProfile()) {
            return new FolderChecklistItem('Rapport d\'adéquation validé et remis', false, sprintf('La version %d cite un profil investisseur révoqué depuis : émettez une nouvelle version.', $report->version), '#heading-adequacy-report', true);
        }

        $detail = $report->madeAvailableAt instanceof \DateTimeImmutable
            ? sprintf('Version %d, remise au client le %s.', $report->version, $report->madeAvailableAt->format('d/m/Y'))
            : sprintf('Version %d validée, PDF en cours de génération.', $report->version);

        return new FolderChecklistItem('Rapport d\'adéquation validé et remis', true, $detail, '#heading-adequacy-report', true);
    }

    private function decisionItem(ComplianceFolder $folder): FolderChecklistItem
    {
        $approved = ComplianceFolderStatus::APPROVED === $folder->status;

        return new FolderChecklistItem(
            'Décision de conformité',
            $approved,
            $approved
                ? sprintf('Dossier approuvé%s.', $folder->riskLevel instanceof \App\Domain\Compliance\Enum\RiskLevel ? ', risque ' . mb_strtolower($folder->riskLevel->getLabel()) : '')
                : (ComplianceFolderStatus::IN_REVIEW === $folder->status ? 'Le dossier attend votre décision.' : 'La décision interviendra une fois le dossier complet.'),
        );
    }
}
