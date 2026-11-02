<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;

final class PortalSecurityController extends AbstractController
{
    /**
     * Cette route est la destination du Magic Link envoyé par email.
     * Le composant "login_link" du security.yaml l'intercepte automatiquement.
     */
    #[Route('/portal/verify', name: 'portal_verify')]
    public function verify(): never
    {
        throw new \LogicException('Intercepté par le firewall.');
    }

    /**
     * Route de déconnexion pour le client final.
     */
    #[Route('/portal/logout', name: 'portal_logout', methods: ['GET'])]
    public function logout(): never
    {
        // Même principe, Symfony intercepte la requête et détruit la session.
        throw new \LogicException('Cette méthode peut rester vide. Elle est interceptée par la clé logout de ton firewall.');
    }
}
