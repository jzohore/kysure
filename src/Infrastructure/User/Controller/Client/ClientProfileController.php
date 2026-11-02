<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Client;

use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients finaux.')]
final class ClientProfileController extends AbstractController
{
    #[Route(path: '/portal/profile', name: 'portal_profile', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        $workspace = $client->workspaces->first();

        return $this->render('@app/client/profile.html.twig', [
            'client' => $client,
            'company_name' => false !== $workspace ? $workspace->name : 'KYSURE',
        ]);
    }
}
