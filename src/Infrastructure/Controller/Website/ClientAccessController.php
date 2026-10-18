<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Website;

use App\Domain\User\Entity\Client;
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

/**
 * Point d'entrée visible des clients finaux des cabinets (distinct de la connexion CGP `/login`).
 * Le formulaire réutilise le flux « lien magique » existant : `SendLoginUserUseCase` route l'e-mail
 * vers le bon type de compte, sans révéler si l'adresse est connue.
 */
#[AsController]
#[Route(path: '/espace-client', name: 'website_client_access', methods: ['GET'])]
final readonly class ClientAccessController
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
        // Un client déjà connecté n'a pas besoin d'un nouveau lien : direction son espace.
        if ($security->getUser() instanceof Client) {
            return new RedirectResponse($urlGenerator->generate('app_portal_dashboard'));
        }

        return new Response(
            $twig->render('@website/client_access.html.twig')
        );
    }
}
