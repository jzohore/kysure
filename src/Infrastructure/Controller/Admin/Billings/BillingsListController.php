<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Admin\Billings;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/admin/billings', name: 'admin_billings_list', methods: ['GET'])]
final class BillingsListController
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
            $twig->render('@admin/billings/list.html.twig', [
                'page_title' => 'Facturations',
            ])
        );
    }
}
