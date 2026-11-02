<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\AuditLogs;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/audit-logs', name: 'admin_audit_logs_list', methods: ['GET'])]
final class AuditLogsListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@admin/audit_logs/list.html.twig', [
            'page_title' => 'Journal d\'audit',
        ]);
    }
}
