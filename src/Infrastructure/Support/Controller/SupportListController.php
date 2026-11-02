<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Controller;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/support', name: 'app_support_list', methods: ['GET'])]
readonly class SupportListController
{
    public function __construct(
        private Environment $twig,
        private Security $security,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(): Response
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new Response(
                $this->twig->render('@admin/support/list.html.twig', [
                    'page_title' => 'Support & Tickets',
                ])
            );
        }

        return new Response(
            $this->twig->render('@app/support/support_list.html.twig', [
                'page_title' => 'Support & Aide',
                'page_description' => 'Comment pouvons-nous vous aider ?',
            ])
        );
    }
}
