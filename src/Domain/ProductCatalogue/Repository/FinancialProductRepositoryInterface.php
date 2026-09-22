<?php

declare(strict_types=1);

namespace App\Domain\ProductCatalogue\Repository;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Workspace\Entity\Workspace;

interface FinancialProductRepositoryInterface
{
    public function findBySlugIdAndWorkspace(string $slugId, Workspace $workspace): ?FinancialProduct;

    /**
     * @return list<FinancialProduct>
     */
    public function findByWorkspace(Workspace $workspace): array;

    public function save(FinancialProduct $financialProduct): void;
}
