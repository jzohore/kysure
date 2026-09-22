<?php

declare(strict_types=1);

namespace App\Application\ProductCatalogue\UseCase;

use App\Application\ProductCatalogue\DTO\Request\FinancialProductRequest;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\ProductCatalogue\Repository\FinancialProductRepositoryInterface;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Workspace\Entity\Workspace;
use Webmozart\Assert\Assert;

readonly class CreateFinancialProductUseCase
{
    private const int PERCENT_TO_BASIS_POINTS = 100;

    public function __construct(
        private FinancialProductRepositoryInterface $financialProductRepository,
    ) {
    }

    public function __invoke(Workspace $workspace, FinancialProductRequest $request): FinancialProduct
    {
        Assert::notNull($request->name);
        Assert::notNull($request->family);
        Assert::notNull($request->sriLevel);
        Assert::notNull($request->minimumHorizonYears);
        Assert::notNull($request->annualFeesPercent);

        $product = FinancialProduct::create(
            workspace: $workspace,
            name: $request->name,
            isin: $request->isin,
            family: ProductFamily::from($request->family),
            sriLevel: $request->sriLevel,
            minimumHorizonYears: $request->minimumHorizonYears,
            annualFeesBasisPoints: (int) round($request->annualFeesPercent * self::PERCENT_TO_BASIS_POINTS),
            targetInvestorProfiles: $request->targetInvestorProfiles,
        );

        $this->financialProductRepository->save($product);

        return $product;
    }
}
