<?php

declare(strict_types=1);

namespace App\Application\Workspace\UseCase;

use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Event\WorkspaceReactivatedEvent;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class ReactivateWorkspaceUseCase
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private WorkspaceRepositoryInterface $workspaceRepository,
    ) {
    }

    public function __invoke(Workspace $workspace, string $actorEmail, string $actorFullName): void
    {
        $workspace->reactivate();
        $this->workspaceRepository->save($workspace);

        $this->eventDispatcher->dispatch(new WorkspaceReactivatedEvent(
            workspace: $workspace,
            email: $actorEmail,
            fullName: $actorFullName,
        ));
    }
}
