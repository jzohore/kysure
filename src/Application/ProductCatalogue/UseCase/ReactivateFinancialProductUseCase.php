<?php

declare(strict_types=1);

namespace App\Application\ProductCatalogue\UseCase;

use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;

readonly class ReactivateFinancialProductUseCase
{
    public function __construct(
        private FinancialProductRepositoryInterface $financialProductRepository,
    ) {
    }

    public function __invoke(FinancialProduct $product): void
    {
        $product->reactivate();
        $this->financialProductRepository->save($product);
    }
}
