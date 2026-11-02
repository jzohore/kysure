<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AccountAdmin;

use App\Application\User\UseCase\Team\ChangeAdminAccountStatusUseCase;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Enum\AdminStatusChange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(
    path: '/admin/team/{slugId}/status/{action}',
    name: 'admin_team_status',
    requirements: ['action' => 'suspend|reactivate|archive'],
    methods: ['POST'],
)]
final class AdminAccountStatusController extends AbstractController
{
    private const array ACTION_MAP = [
        'suspend' => AdminStatusChange::SUSPEND,
        'reactivate' => AdminStatusChange::REACTIVATE,
        'archive' => AdminStatusChange::ARCHIVE,
    ];

    public function __construct(
        private readonly ChangeAdminAccountStatusUseCase $changeStatus,
    ) {
    }

    public function __invoke(Request $request, string $slugId, string $action): RedirectResponse
    {
        $operator = $this->getUser();
        if (!$operator instanceof Admin) {
            throw new AccessDeniedException('Opérateur non identifié.');
        }

        if (!isset(self::ACTION_MAP[$action])) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin_account_status_' . $slugId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $this->redirectToRoute('admin_team_list');
        }

        try {
            $this->changeStatus->__invoke(
                $slugId,
                self::ACTION_MAP[$action],
                $operator->email,
                $operator->getFullName(),
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_team_list');
        }

        $this->addFlash('success', match ($action) {
            'suspend' => 'Compte suspendu. Ses sessions ont été fermées et il en est informé par e-mail.',
            'reactivate' => 'Compte réactivé. Le membre en est informé par e-mail.',
            'archive' => 'Compte archivé. Le membre en est informé par e-mail.',
        });

        return $this->redirectToRoute('admin_team_list');
    }
}
