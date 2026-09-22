<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Controller;

use App\Application\ProductCatalogue\UseCase\ListFinancialProductsUseCase;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/settings/product-catalogue', name: 'app_settings_product_catalogue_list')]
class ProductCatalogueListController extends AbstractController
{
    public function __construct(
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly ListFinancialProductsUseCase $listFinancialProducts,
    ) {
    }

    public function __invoke(): Response
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();
        $this->denyAccessUnlessGranted(WorkspaceInvitationVoter::WORKSPACE_EDIT, $workspace);

        return $this->render('@app/settings/product_catalogue.html.twig', [
            'page_title' => 'Paramètres - Catalogue produits',
            'sub_title' => 'Les supports financiers que vous recommandez à vos clients.',
            'products' => ($this->listFinancialProducts)($workspace),
        ]);
    }
}
