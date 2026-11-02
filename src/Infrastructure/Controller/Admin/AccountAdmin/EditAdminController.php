<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AccountAdmin;

use App\Application\User\DTO\Request\UpdateAdminAccountInput;
use App\Application\User\UseCase\Team\GetAdminAccountUseCase;
use App\Application\User\UseCase\Team\UpdateAdminAccountUseCase;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Enum\AdminRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/team/{slugId}/edit', name: 'admin_team_edit', methods: ['GET', 'POST'])]
final class EditAdminController extends AbstractController
{
    public function __construct(
        private readonly GetAdminAccountUseCase $getAdminAccount,
        private readonly UpdateAdminAccountUseCase $updateAdminAccount,
    ) {
    }

    public function __invoke(Request $request, string $slugId): Response
    {
        try {
            $account = $this->getAdminAccount->__invoke($slugId);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_team_list');
        }

        if ($request->isMethod('POST')) {
            return $this->handle($request, $slugId);
        }

        return $this->render('@admin/account_admin/edit_admin.html.twig', [
            'page_title' => 'Modifier — ' . $account->firstName . ' ' . $account->lastName,
            'account' => $account,
            'roles' => AdminRole::cases(),
        ]);
    }

    private function handle(Request $request, string $slugId): RedirectResponse
    {
        $operator = $this->getUser();
        if (!$operator instanceof Admin) {
            throw new AccessDeniedException('Opérateur non identifié.');
        }

        if (!$this->isCsrfTokenValid('admin_account_edit_' . $slugId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_team_edit', ['slugId' => $slugId]);
        }

        $phone = trim((string) $request->request->get('phoneNumber'));

        try {
            $this->updateAdminAccount->__invoke(
                new UpdateAdminAccountInput(
                    slugId: $slugId,
                    firstName: (string) $request->request->get('firstName'),
                    lastName: (string) $request->request->get('lastName'),
                    phoneNumber: '' !== $phone ? $phone : null,
                    role: (string) $request->request->get('role'),
                ),
                $operator->email,
                $operator->getFullName(),
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_team_edit', ['slugId' => $slugId]);
        }

        $this->addFlash('success', 'Compte mis à jour.');

        return $this->redirectToRoute('admin_team_list');
    }
}
