<?php

declare(strict_types=1);

namespace App\Application\ClientFile\UseCase;

use App\Domain\ClientFile\Entity\ClientFile;
use App\Domain\ClientFile\Repository\ClientFileRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;

readonly class FindClientFileUseCase
{
    public function __construct(
        private ClientFileRepositoryInterface $clientFileRepository,
    ) {
    }

    public function __invoke(ComplianceFolder $complianceFolder): ?ClientFile
    {
        return $this->clientFileRepository->findByComplianceFolder($complianceFolder);
    }
}
