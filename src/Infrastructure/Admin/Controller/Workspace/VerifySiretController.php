<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Controller\Workspace;

use App\Application\Workspace\UseCase\VerifySiret\VerifyWorkspaceSiretUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/workspaces/{slug}/verify-siret', name: 'admin_workspaces_verify_siret', methods: ['POST'])]
final class VerifySiretController extends AbstractController
{
    public function __construct(private readonly VerifyWorkspaceSiretUseCase $useCase)
    {
    }

    public function __invoke(
        string $slug,
        Request $request,
    ): RedirectResponse {
        $token = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('verify_siret_' . $slug, $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_workspaces_show', ['slugId' => $slug]);
        }
        try {
            $adminEmail = $this->getUser()?->getUserIdentifier() ?? 'admin@kysure.fr';

            // Appel direct du UseCase Métier (Sans lancer de processus CLI)
            ($this->useCase)($slug, $adminEmail);

            $this->addFlash('success', 'Vérification SIRET effectuée et synchronisée avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_workspaces_show', ['slugId' => $slug]);
    }
}
