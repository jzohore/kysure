<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Controller;

use App\Application\ProductCatalogue\DTO\Request\FinancialProductRequest;
use App\Application\ProductCatalogue\UseCase\FindFinancialProductUseCase;
use App\Application\ProductCatalogue\UseCase\UpdateFinancialProductUseCase;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Infrastructure\ProductCatalogue\Form\FinancialProductType;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/product-catalogue/{slugId}/edit', name: 'app_product_catalogue_edit', methods: ['GET', 'POST'])]
final class EditFinancialProductController extends AbstractController
{
    public function __construct(
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly FindFinancialProductUseCase $findFinancialProduct,
        private readonly UpdateFinancialProductUseCase $updateFinancialProduct,
    ) {
    }

    public function __invoke(string $slugId, Request $request): Response
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();
        $this->denyAccessUnlessGranted(WorkspaceInvitationVoter::WORKSPACE_EDIT, $workspace);

        // Le produit doit appartenir au cabinet courant : la portée workspace est ici la
        // seule barrière d'accès, il n'y a pas de liste blanche de confidentialité par produit.
        $product = ($this->findFinancialProduct)($slugId, $workspace);
        if (!$product instanceof FinancialProduct) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(FinancialProductType::class, $this->buildInitialRequest($product));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var FinancialProductRequest $dto */
            $dto = $form->getData();

            try {
                ($this->updateFinancialProduct)($product, $dto);
                $this->addFlash('success', 'Produit mis à jour.');

                return $this->redirectToRoute('app_product_catalogue_list');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('@app/product_catalogue/product_catalogue_form.html.twig', [
            'page_title' => 'Modifier ' . $product->name,
            'product_form' => $form,
            'product' => $product,
        ]);
    }

    private function buildInitialRequest(FinancialProduct $product): FinancialProductRequest
    {
        $dto = new FinancialProductRequest();
        $dto->name = $product->name;
        $dto->isin = $product->isin;
        $dto->family = $product->family->value;
        $dto->sriLevel = $product->sriLevel;
        $dto->minimumHorizonYears = $product->minimumHorizonYears;
        $dto->annualFeesPercent = $product->annualFeesBasisPoints / 100;
        $dto->targetInvestorProfiles = $product->targetInvestorProfiles;

        return $dto;
    }
}
