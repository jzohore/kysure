<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Compliance;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route(path: '/admin/compliance', name: 'admin_compliance_list', methods: ['GET'])]
final class ComplianceListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@admin/compliance/list.html.twig', [
            'page_title' => 'Dossiers de conformité',
        ]);
    }
}
