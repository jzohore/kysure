<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Controller;

use App\Application\ProductCatalogue\DTO\Request\FinancialProductRequest;
use App\Application\ProductCatalogue\UseCase\CreateFinancialProductUseCase;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use App\Infrastructure\ProductCatalogue\Form\FinancialProductType;
use App\Infrastructure\Workspace\Voter\WorkspaceInvitationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ajout d'un produit au catalogue du cabinet (lot 2 du chantier "rapport d'adéquation" — cf.
 * mémoire cif-pilot-roadmap-phase2). Formulaire Symfony classique : écran de réglages en
 * back-office, pas le chemin critique client.
 */
#[AsController]
#[Route(path: '/app/settings/product-catalogue/new', name: 'app_settings_product_catalogue_new', methods: ['GET', 'POST'])]
final class NewFinancialProductController extends AbstractController
{
    public function __construct(
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly CreateFinancialProductUseCase $createFinancialProduct,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();
        $this->denyAccessUnlessGranted(WorkspaceInvitationVoter::WORKSPACE_EDIT, $workspace);

        $form = $this->createForm(FinancialProductType::class, new FinancialProductRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var FinancialProductRequest $dto */
            $dto = $form->getData();

            try {
                ($this->createFinancialProduct)($workspace, $dto);
                $this->addFlash('success', 'Produit ajouté au catalogue.');

                return $this->redirectToRoute('app_settings_product_catalogue_list');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('@app/settings/product_catalogue_form.html.twig', [
            'page_title' => 'Nouveau produit',
            'product_form' => $form,
        ]);
    }
}
