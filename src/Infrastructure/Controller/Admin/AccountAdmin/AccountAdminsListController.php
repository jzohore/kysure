<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AccountAdmin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/team', name: 'admin_team_list', methods: ['GET'])]
final class AccountAdminsListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@admin/account_admin/list.html.twig', [
            'page_title' => 'Équipe KYSURE',
        ]);
    }
}
