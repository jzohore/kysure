<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Repository;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;

interface AdequacyReportRepositoryInterface
{
    /**
     * Volontairement pas de `remove()` : un rapport validé est une pièce d'audit, il se
     * révoque, il ne se supprime pas.
     */
    public function save(AdequacyReport $report, bool $flush = true): void;

    public function findBySlugId(string $slugId): ?AdequacyReport;

    /**
     * Historique des rapports du dossier, du plus récent (version la plus haute) au plus ancien.
     *
     * @return list<AdequacyReport>
     */
    public function findByFolder(ComplianceFolder $folder): array;

    /** La version en vigueur (non révoquée) du dossier, ou `null`. */
    public function findInForceByFolder(ComplianceFolder $folder): ?AdequacyReport;

    /** Le plus grand numéro de version émis pour ce dossier (0 si aucun). */
    public function findLatestVersionNumber(ComplianceFolder $folder): int;

    /**
     * Rapports en vigueur fondés sur ce profil investisseur — à signaler s'il est révoqué.
     *
     * @return list<AdequacyReport>
     */
    public function findInForceByInvestorProfile(ValidatedInvestorProfile $profile): array;
}
