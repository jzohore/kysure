<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Cron;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/administrators/crons/list', name: 'admin_crons_list', methods: ['GET'])]
final class CronListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@admin/cron/list.html.twig', [
            'page_title' => 'Tâches planifiées',
        ]);
    }
}
