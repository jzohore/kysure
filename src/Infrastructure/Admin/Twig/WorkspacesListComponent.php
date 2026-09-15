<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'WorkspacesListComponent',
    template: 'components/Admin/Workspace/WorkspacesListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class WorkspacesListComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: false)]
    public ?string $query = null;

    #[LiveProp(writable: true, url: false)]
    public int $page = 1;

    #[LiveAction]
    public function previousPage(): void
    {
        if ($this->page > 1) {
            --$this->page;
        }
    }

    #[LiveAction]
    public function nextPage(): void
    {
        ++$this->page;
    }

    public function __construct(
        private readonly WorkspaceRepositoryInterface $workspaceRepository,
    ) {
    }

    /**
     * @return Pagerfanta<Workspace>
     */
    public function getWorkspaces(): Pagerfanta
    {
        return $this->workspaceRepository->getPaginatedWorkspaces($this->page, 10, search: $this->query);
    }
}
