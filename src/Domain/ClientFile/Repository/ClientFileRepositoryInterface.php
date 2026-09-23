<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\Repository;

use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\Compliance\Entity\ComplianceFolder;

interface ClientFileRepositoryInterface
{
    public function findByComplianceFolder(ComplianceFolder $complianceFolder): ?ClientFile;

    public function save(ClientFile $clientFile): void;
}
