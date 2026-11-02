<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AccountAdmin;

use App\Application\User\UseCase\Team\DeleteAdminAccountUseCase;
use App\Domain\User\Entity\Admin;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/team/{slugId}/delete', name: 'admin_team_delete', methods: ['POST'])]
final class DeleteAdminController extends AbstractController
{
    public function __construct(
        private readonly DeleteAdminAccountUseCase $deleteAdminAccount,
    ) {
    }

    public function __invoke(Request $request, string $slugId): RedirectResponse
    {
        $operator = $this->getUser();
        if (!$operator instanceof Admin) {
            throw new AccessDeniedException('Opérateur non identifié.');
        }

        if (!$this->isCsrfTokenValid('admin_account_delete_' . $slugId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_team_list');
        }

        try {
            $this->deleteAdminAccount->__invoke($slugId, $operator->email, $operator->getFullName());
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_team_list');
        }

        $this->addFlash('success', 'Compte supprimé définitivement.');

        return $this->redirectToRoute('admin_team_list');
    }
}
