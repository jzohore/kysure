<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Controller\Workspace;

use App\Application\Workspace\UseCase\GetWorkspaceDetailsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/admin/workspaces/{slugId}', name: 'admin_workspaces_show')]
final class WorkspaceDetailsController extends AbstractController
{
    public function __construct(
        private readonly GetWorkspaceDetailsUseCase $getWorkspaceDetailsUseCase,
    ) {
    }

    public function __invoke(string $slugId): Response
    {
        $dto = ($this->getWorkspaceDetailsUseCase)($slugId);

        return $this->render('@admin/workspace/show.html.twig', [
            'workspace' => $dto,
            'page_title' => 'Détails du workspace :' . $dto->name,
        ]);
    }
}
