<?php

declare(strict_types=1);

namespace App\Infrastructure\Screening\Controller;

use App\Domain\User\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/screening', name: 'app_screening_list', methods: ['GET', 'POST'])]
class ScreeningListController
{
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
        #[CurrentUser]
        User $user,
    ): Response {
        return new Response(
            $twig->render('@app/screening/screening_list.html.twig', [
                'page_title' => 'Criblage LCB-FT',
            ])
        );
    }
}
