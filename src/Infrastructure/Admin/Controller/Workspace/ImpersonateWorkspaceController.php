<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Controller\Workspace;

use App\Application\Support\UseCase\StartSupportImpersonationUseCase;
use App\Application\Workspace\UseCase\GetWorkspaceDetailsUseCase;
use App\Domain\User\Entity\Admin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use function Symfony\Component\Clock\now;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Point d'entrée de la connexion support (impersonation d'un collaborateur).
 *
 * L'opérateur est authentifié ici sur le firewall « admin » (entité Admin). La
 * cible vit sur le firewall « main » (entité User) : on ne peut donc pas passer
 * par le switch_user de Symfony. Après saisie d'un motif, on trace l'entrée dans
 * le journal du cabinet puis on dépose un jeton d'authentification « main » en
 * session — les deux sessions (admin + main) coexistent, l'opérateur reste
 * connecté au back-office et peut quitter la session support à tout moment
 * (bannière → app_support_impersonate_stop).
 */
#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/workspaces/{slugId}/impersonate', name: 'admin_workspaces_impersonate', methods: ['GET', 'POST'])]
final class ImpersonateWorkspaceController extends AbstractController
{
    public const string SESSION_CONTEXT_KEY = '_support_impersonation';
    private const string MAIN_FIREWALL = 'main';

    public function __construct(
        private readonly GetWorkspaceDetailsUseCase $getWorkspaceDetails,
        private readonly StartSupportImpersonationUseCase $startImpersonation,
    ) {
    }

    public function __invoke(Request $request, string $slugId): Response
    {
        $workspace = ($this->getWorkspaceDetails)($slugId);

        if ($request->isMethod('POST')) {
            return $this->handle($request, $slugId, $workspace->name);
        }

        return $this->render('@admin/workspace/impersonate.html.twig', [
            'page_title' => 'Connexion support — ' . $workspace->name,
            'slug_id' => $slugId,
            'workspace_name' => $workspace->name,
            'target_email' => (string) $request->query->get('email', $workspace->ownerEmail ?? ''),
        ]);
    }

    private function handle(Request $request, string $slugId, string $workspaceName): RedirectResponse
    {
        $operator = $this->getUser();
        if (!$operator instanceof Admin) {
            throw new AccessDeniedException('Opérateur non identifié.');
        }

        $email = trim((string) $request->request->get('target_email'));
        $reason = trim((string) $request->request->get('reason'));

        if (!$this->isCsrfTokenValid('impersonate_' . $slugId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_workspaces_impersonate', ['slugId' => $slugId, 'email' => $email]);
        }

        try {
            $target = ($this->startImpersonation)(
                workspaceSlugId: $slugId,
                operatorEmail: $operator->email,
                operatorName: $operator->getFullName(),
                targetEmail: $email,
                reason: $reason,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_workspaces_impersonate', ['slugId' => $slugId, 'email' => $email]);
        }

        // Dépose l'identité « main » en session, sans toucher à « _security_admin ».
        $token = new PostAuthenticationToken($target, self::MAIN_FIREWALL, $target->getRoles());
        $session = $request->getSession();
        $session->set('_security_' . self::MAIN_FIREWALL, serialize($token));
        $session->set(self::SESSION_CONTEXT_KEY, [
            'operator_email' => $operator->email,
            'operator_name' => $operator->getFullName(),
            'target_email' => $target->email,
            'target_name' => $target->getFullName(),
            'workspace_slug' => $slugId,
            'workspace_name' => $workspaceName,
            'started_at' => now()->format(\DateTimeInterface::ATOM),
            'reason' => $reason,
        ]);

        $this->addFlash('success', sprintf('Session support ouverte en tant que %s.', $target->getFullName()));

        return $this->redirectToRoute('app_dashboard');
    }
}
