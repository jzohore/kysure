<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/compliance', name: 'app_compliance_list', methods: ['GET'])]
class ComplianceListController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@app/compliance/compliance_list.html.twig', [
            'page_title' => 'Dossiers KYC / KYB',
        ]);
    }
}
