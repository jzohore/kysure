<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Repository;

use App\Domain\AdequacyReport\Entity\ProductAdequacyCheck;
use App\Domain\Compliance\Entity\ComplianceFolder;

interface ProductAdequacyCheckRepositoryInterface
{
    public function save(ProductAdequacyCheck $check, bool $flush = true): void;

    public function findBySlugId(string $slugId): ?ProductAdequacyCheck;

    /**
     * Vérifications du dossier, de la plus récente à la plus ancienne.
     *
     * @return list<ProductAdequacyCheck>
     */
    public function findByFolder(ComplianceFolder $folder, int $limit = 20): array;
}
