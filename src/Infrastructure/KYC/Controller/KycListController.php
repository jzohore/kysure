<?php

declare(strict_types=1);

namespace App\Infrastructure\KYC\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/kyc', name: 'app_kyc_list', methods: ['GET', 'POST'])]
final class KycListController
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
            $twig->render('@app/kyc/kyc_list.html.twig', [
                'page_title' => 'Dossiers KYC / LCB-FT',
            ])
        );
    }
}
