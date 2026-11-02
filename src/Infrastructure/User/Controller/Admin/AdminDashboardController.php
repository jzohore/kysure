<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Admin;

use App\Application\Dashboard\UseCase\GetAdminDashboardStatsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly GetAdminDashboardStatsUseCase $adminDashboardStatsUseCase,
    ) {
    }

    #[Route(path: '/admin/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('@admin/dashboard.html.twig', [
            'page_title' => 'Centre de Contrôle',
            'stats' => ($this->adminDashboardStatsUseCase)(),
        ]);
    }
}
