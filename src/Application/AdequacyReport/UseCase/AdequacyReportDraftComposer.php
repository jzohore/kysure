<?php

declare(strict_types=1);

namespace App\Application\AdequacyReport\UseCase;

use App\Application\AdequacyReport\DTO\AdequacyReportDraft;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\ClientFile\ValueObject\InvestmentObjective;
use App\Domain\ClientFile\ValueObject\NetWorthLine;
use App\Domain\ClientFile\ValueObject\NetWorthStatement;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;

/**
 * Assemble le brouillon d'un rapport d'adéquation (lot 5) : le profil en vigueur, les
 * vérifications choisies par le CGP, et les copies de l'identité du cabinet et de la situation
 * du client telles qu'elles sont à cet instant. Rien n'est persisté : le même assemblage sert
 * à l'aperçu du brouillon et à la validation, pour que l'aperçu soit exactement ce qui sera figé.
 */
final readonly class AdequacyReportDraftComposer
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private ProductAdequacyCheckRepositoryInterface $checkRepository,
        private ClientFileRepositoryInterface $clientFileRepository,
    ) {
    }

    /**
     * Vérifications du dossier pouvant fonder un rapport : celles faites sur le profil
     * investisseur EN VIGUEUR (une vérification sur une version révoquée ne le peut plus).
     *
     * @return list<ProductAdequacyCheck>
     */
    public function eligibleChecks(ComplianceFolder $folder): array
    {
        $profile = $this->inForceProfile($folder);
        if (!$profile instanceof ValidatedInvestorProfile) {
            return [];
        }

        return array_values(array_filter(
            $this->checkRepository->findByFolder($folder, 50),
            static fn (ProductAdequacyCheck $check): bool => $check->investorProfile === $profile,
        ));
    }

    /**
     * @param list<string> $checkSlugIds
     *
     * @throws \DomainException si un prérequis manque ou si une règle du rapport n'est pas respectée
     */
    public function compose(ComplianceFolder $folder, array $checkSlugIds, string $advisorRationale): AdequacyReportDraft
    {
        $profile = $this->inForceProfile($folder);
        if (!$profile instanceof ValidatedInvestorProfile) {
            throw new \DomainException('Aucun profil investisseur validé n\'est en vigueur pour ce client.');
        }

        $checks = [];
        foreach (array_values(array_unique($checkSlugIds)) as $slugId) {
            $check = $this->checkRepository->findBySlugId($slugId);
            if (!$check instanceof ProductAdequacyCheck) {
                throw new \DomainException('Une vérification sélectionnée est introuvable.');
            }

            $checks[] = $check;
        }

        $cabinet = $this->cabinetSnapshot($folder);
        $clientSituation = $this->clientSituationSnapshot($folder);

        return new AdequacyReportDraft(
            folder: $folder,
            investorProfile: $profile,
            checks: $checks,
            cabinet: $cabinet,
            clientSituation: $clientSituation,
            content: AdequacyReport::composeContent($folder, $profile, $checks, $advisorRationale, $cabinet, $clientSituation),
        );
    }

    private function inForceProfile(ComplianceFolder $folder): ?ValidatedInvestorProfile
    {
        return $folder->client instanceof Client
            ? $this->profileRepository->findInForceByClient($folder->client, $folder->workspace)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function cabinetSnapshot(ComplianceFolder $folder): array
    {
        $workspace = $folder->workspace;
        $regulatory = $workspace->regulatoryProfile;

        return [
            'name' => $workspace->name,
            'legalName' => $workspace->legalName,
            'address' => $workspace->address,
            'siret' => $workspace->siret,
            'oriasNumber' => $regulatory?->oriasNumber,
            'professionalAssociation' => $regulatory?->professionalAssociation,
            'logoStoragePath' => $regulatory?->logoStoragePath,
        ];
    }

    /**
     * Situation du client recopiée depuis la fiche patrimoniale, libellés compris : le rapport
     * reste lisible même si la fiche ou les libellés du code évoluent.
     *
     * @return array<string, mixed>
     */
    private function clientSituationSnapshot(ComplianceFolder $folder): array
    {
        $clientFile = $this->clientFileRepository->findByComplianceFolder($folder);
        if (!$clientFile instanceof ClientFile) {
            return ['situation' => null, 'netWorth' => null, 'objectives' => []];
        }

        $netWorth = $clientFile->netWorthStatement();

        return [
            'situation' => [
                'civility' => $clientFile->civility?->getLabel(),
                'birthDate' => $clientFile->birthDate?->format('Y-m-d'),
                'maritalStatus' => $clientFile->maritalStatus?->getLabel(),
                'childrenCount' => $clientFile->childrenCount,
                'professionalStatus' => $clientFile->professionalStatus?->getLabel(),
                'profession' => $clientFile->profession,
            ],
            'netWorth' => $netWorth instanceof NetWorthStatement ? [
                'lines' => array_map(static fn (NetWorthLine $line): array => [
                    'label' => $line->assetClass->getLabel(),
                    'amountInCents' => $line->amountInCents,
                ], $netWorth->lines),
                'grossWorthInCents' => $netWorth->grossWorth(),
                'outstandingDebtInCents' => $netWorth->outstandingDebtInCents,
                'netWorthInCents' => $netWorth->netWorth(),
                'annualIncomeInCents' => $netWorth->annualIncomeInCents,
                'annualExpensesInCents' => $netWorth->annualExpensesInCents,
                'investmentCapacityInCents' => $netWorth->investmentCapacityInCents,
            ] : null,
            'objectives' => array_map(static fn (InvestmentObjective $objective): array => [
                'label' => $objective->type->getLabel(),
                'priorityLabel' => $objective->priority->getLabel(),
                'horizonLabel' => $objective->horizon->getLabel(),
                'amountInCents' => $objective->amountInCents,
            ], $clientFile->investmentObjectives()),
        ];
    }
}
