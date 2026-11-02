<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\IntegrationApi;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/admin/integrations', name: 'admin_integration_api_list', methods: ['GET'])]
final class ApisListController
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
            $twig->render('@admin/integration_api/list.html.twig', [
                'page_title' => 'Integrations API',
            ])
        );
    }
}
