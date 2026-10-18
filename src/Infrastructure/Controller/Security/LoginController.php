<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Security;

use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
class LoginController
{
    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
        Security $security,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        $currentUser = $security->getUser();

        // 1. Si c'est un professionnel (CGP) déjà connecté
        if ($currentUser instanceof User) {
            return new RedirectResponse($urlGenerator->generate('app_dashboard'));
        }

        // 2. Si c'est un client final déjà connecté
        if ($currentUser instanceof Client) {
            return new RedirectResponse($urlGenerator->generate('app_portal_dashboard'));
        }

        return new Response(
            $twig->render('@security/login.html.twig', [
                'page_title' => 'Votre tableau de bord',
            ])
        );
    }
}
