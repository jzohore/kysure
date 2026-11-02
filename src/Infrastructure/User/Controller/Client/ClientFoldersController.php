<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Client;

use App\Application\Portal\UseCase\GetClientFoldersUseCase;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_CLIENT')]
final class ClientFoldersController extends AbstractController
{
    public function __construct(
        private readonly GetClientFoldersUseCase $getClientFoldersUseCase,
    ) {
    }

    #[Route(path: '/portal/folders', name: 'portal_folders_list', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        $workspace = $client->workspaces->first();

        return $this->render('@app/client/folders.html.twig', [
            'folders' => ($this->getClientFoldersUseCase)($client),
            'company_name' => false !== $workspace ? $workspace->name : 'KYSURE',
        ]);
    }
}
