<?php

declare(strict_types=1);

namespace App\Application\ProductCatalogue\UseCase;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;

readonly class ListFinancialProductsUseCase
{
    public function __construct(
        private FinancialProductRepositoryInterface $financialProductRepository,
    ) {
    }

    /**
     * @return list<FinancialProduct>
     */
    public function __invoke(Workspace $workspace): array
    {
        return $this->financialProductRepository->findByWorkspace($workspace);
    }
}
