<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Twig\Components;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Historique des tickets du cabinet courant (ouverts + résolus) — "Mes
 * tickets", accessible à tout membre du workspace (contrairement au thread
 * "actif" unique montré par le widget de tchat flottant).
 */
#[AsLiveComponent(
    name: 'MySupportTicketsComponent',
    template: 'components/Support/MySupportTicketsComponent.html.twig',
)]
class MySupportTicketsComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: false)]
    public int $page = 1;

    #[LiveProp(writable: true, url: false)]
    public ?SupportThreadStatus $statusFilter = null;

    public function __construct(
        private readonly SupportThreadRepositoryInterface $supportThreadRepository,
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
    ) {
    }

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

    /**
     * @return Pagerfanta<SupportThread>
     */
    public function getThreads(): Pagerfanta
    {
        $threads = $this->supportThreadRepository->getPaginatedSupportForWorkspace(
            $this->currentWorkspaceProvider->getWorkspace(),
            $this->statusFilter,
        );
        $threads->setMaxPerPage(5);
        $threads->setCurrentPage($this->page);

        return $threads;
    }

    /**
     * @return array<SupportThreadStatus>
     */
    public function getAvailableStatuses(): array
    {
        return SupportThreadStatus::cases();
    }
}
