<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Client;

use App\Application\Portal\UseCase\GetFolderDetailUseCase;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_CLIENT')]
final class FolderDetailController extends AbstractController
{
    public function __construct(
        private readonly GetFolderDetailUseCase $getFolderDetailUseCase,
    ) {
    }

    #[Route(path: '/portal/folders/{id}', name: 'portal_folders_show', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        $folderDetailDto = ($this->getFolderDetailUseCase)($client, $id);

        $workspace = $client->workspaces->first();

        return $this->render('@app/client/folder_detail.html.twig', [
            'folder' => $folderDetailDto,
            'company_name' => false !== $workspace ? $workspace->name : 'KYSURE',
        ]);
    }
}
