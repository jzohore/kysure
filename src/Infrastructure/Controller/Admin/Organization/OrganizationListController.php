<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Organization;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/admin/workspaces', name: 'admin_workspaces_list', methods: ['GET'])]
final class OrganizationListController
{
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
    ): Response {
        return new Response(
            $twig->render('@admin/organization/list.html.twig', [
                'page_title' => 'Workspaces & clients',
            ])
        );
    }
}
