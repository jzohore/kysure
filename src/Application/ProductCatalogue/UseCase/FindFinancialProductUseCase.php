<?php

declare(strict_types=1);

namespace App\Application\ProductCatalogue\UseCase;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;

readonly class FindFinancialProductUseCase
{
    public function __construct(
        private FinancialProductRepositoryInterface $financialProductRepository,
    ) {
    }

    public function __invoke(string $slugId, Workspace $workspace): ?FinancialProduct
    {
        return $this->financialProductRepository->findBySlugIdAndWorkspace($slugId, $workspace);
    }
}
