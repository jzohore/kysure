<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Repository;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\ElectronicDeliveryConsent;

interface ElectronicDeliveryConsentRepositoryInterface
{
    /** Pas de `remove()` : un accord est une preuve, il ne se supprime pas. */
    public function save(ElectronicDeliveryConsent $consent, bool $flush = true): void;

    /** Le dernier accord donné par avenant pour ce dossier, ou `null`. */
    public function findLatestByFolder(ComplianceFolder $folder): ?ElectronicDeliveryConsent;
}
