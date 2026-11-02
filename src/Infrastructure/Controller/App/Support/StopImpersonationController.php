<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\App\Support;

use App\Application\Support\UseCase\StopSupportImpersonationUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ferme une connexion support : trace la sortie dans le journal du cabinet, purge
 * le jeton « main » et le contexte de session, puis renvoie l'opérateur au
 * back-office (sa session « admin » est restée intacte pendant toute la bascule).
 */
#[AsController]
#[Route(path: '/app/support/impersonate/stop', name: 'app_support_impersonate_stop', methods: ['POST'])]
final class StopImpersonationController extends AbstractController
{
    /** Doit rester aligné avec ImpersonateWorkspaceController::SESSION_CONTEXT_KEY. */
    private const string SESSION_CONTEXT_KEY = '_support_impersonation';

    public function __construct(
        private readonly StopSupportImpersonationUseCase $stopImpersonation,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $session = $request->getSession();
        /** @var array<string, mixed>|null $context */
        $context = $session->get(self::SESSION_CONTEXT_KEY);

        if (!$this->isCsrfTokenValid('stop-impersonation', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_dashboard');
        }

        if (\is_array($context)) {
            ($this->stopImpersonation)($context);
        }

        $session->remove(self::SESSION_CONTEXT_KEY);
        $session->remove('_security_main');
        $this->tokenStorage->setToken(null);

        $slugId = \is_array($context) ? (string) ($context['workspace_slug'] ?? '') : '';

        return '' !== $slugId
            ? $this->redirectToRoute('admin_workspaces_show', ['slugId' => $slugId])
            : $this->redirectToRoute('admin_dashboard');
    }
}
