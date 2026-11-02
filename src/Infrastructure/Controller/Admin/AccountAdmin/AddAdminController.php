<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AccountAdmin;

use App\Application\User\DTO\Request\CreateAdminAccountInput;
use App\Application\User\UseCase\Team\CreateAdminAccountUseCase;
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
#[Route(path: '/admin/team/new', name: 'admin_team_new', methods: ['GET', 'POST'])]
final class AddAdminController extends AbstractController
{
    public function __construct(
        private readonly CreateAdminAccountUseCase $createAdminAccount,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handle($request);
        }

        return $this->render('@admin/account_admin/add_admin.html.twig', [
            'page_title' => 'Nouveau membre de l\'équipe',
            'roles' => AdminRole::cases(),
        ]);
    }

    private function handle(Request $request): RedirectResponse
    {
        $operator = $this->getUser();
        if (!$operator instanceof Admin) {
            throw new AccessDeniedException('Opérateur non identifié.');
        }

        if (!$this->isCsrfTokenValid('admin_account_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_team_new');
        }

        $phone = trim((string) $request->request->get('phoneNumber'));

        try {
            $this->createAdminAccount->__invoke(
                new CreateAdminAccountInput(
                    email: (string) $request->request->get('email'),
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

            return $this->redirectToRoute('admin_team_new');
        }

        $this->addFlash('success', 'Compte créé. Un e-mail de bienvenue vient d\'être envoyé au nouveau membre.');

        return $this->redirectToRoute('admin_team_list');
    }
}
