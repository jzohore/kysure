<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Controller;

use App\Application\ProductCatalogue\UseCase\ArchiveFinancialProductUseCase;
use App\Application\ProductCatalogue\UseCase\FindFinancialProductUseCase;
use App\Application\ProductCatalogue\UseCase\ReactivateFinancialProductUseCase;
use App\Domain\ProductCatalogue\Entity\FinancialProduct;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/settings/product-catalogue/{slugId}/toggle-archive', name: 'app_settings_product_catalogue_toggle_archive', methods: ['POST'])]
final class ToggleFinancialProductArchiveController extends AbstractController
{
    public function __construct(
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly FindFinancialProductUseCase $findFinancialProduct,
        private readonly ArchiveFinancialProductUseCase $archiveFinancialProduct,
        private readonly ReactivateFinancialProductUseCase $reactivateFinancialProduct,
    ) {
    }

    public function __invoke(string $slugId, Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();
        $this->denyAccessUnlessGranted(WorkspaceInvitationVoter::WORKSPACE_EDIT, $workspace);

        $product = ($this->findFinancialProduct)($slugId, $workspace);
        if (!$product instanceof FinancialProduct) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('product_toggle_archive_' . $slugId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_settings_product_catalogue_list');
        }

        try {
            if ($product->isArchived()) {
                ($this->reactivateFinancialProduct)($product);
                $this->addFlash('success', 'Produit réactivé.');
            } else {
                ($this->archiveFinancialProduct)($product);
                $this->addFlash('success', 'Produit archivé.');
            }
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_settings_product_catalogue_list');
    }
}
