<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Twig\Components;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportCategory;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Domain\User\Entity\Admin;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'AdminSupportListComponent',
    template: 'components/Support/AdminSupportListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminSupportListComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: false)]
    public ?string $query = null;

    #[LiveProp(writable: true, url: false)]
    public int $page = 1;

    #[LiveProp(writable: true, url: false)]
    public ?SupportThreadStatus $statusFilter = null;

    #[LiveProp(writable: true, url: false)]
    public ?string $categoryFilter = null;

    #[LiveProp(writable: true, url: false)]
    public ?string $fromDate = null;

    #[LiveProp(writable: true, url: false)]
    public ?string $toDate = null;

    #[LiveProp(writable: true, url: false)]
    public bool $assignedToMeOnly = false;

    #[LiveProp(writable: true, url: false)]
    public string $sortBy = 'priority';

    #[LiveProp(writable: true, url: false)]
    public string $sortDir = 'DESC';

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
        private readonly SupportThreadRepositoryInterface $supportThreadRepository,
        private readonly Security $security,
    ) {
    }

    /**
     * @return Pagerfanta<SupportThread>
     */
    public function getSupportThreads(): Pagerfanta
    {
        $assignedToAdminId = null;
        if ($this->assignedToMeOnly) {
            $currentAdmin = $this->security->getUser();
            $assignedToAdminId = $currentAdmin instanceof Admin ? (string) $currentAdmin->id : null;
        }

        $kyc = $this->supportThreadRepository->getPaginatedSupport(
            search: $this->query,
            statusFilter: $this->statusFilter,
            categoryFilter: $this->categoryFilter,
            fromDate: $this->parseDate($this->fromDate),
            toDate: $this->parseDate($this->toDate),
            assignedToAdminId: $assignedToAdminId,
            sortBy: $this->sortBy,
            sortDir: $this->sortDir,
        );
        $kyc->setMaxPerPage(10);
        $kyc->setCurrentPage($this->page);

        return $kyc;
    }

    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if (in_array($date, [null, '', '0'], true)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return false !== $parsed ? $parsed : null;
    }

    /**
     * @return array<SupportThreadStatus>
     */
    public function getAvailableStatuses(): array
    {
        return SupportThreadStatus::cases();
    }

    /**
     * @return array<SupportCategory>
     */
    public function getAvailableCategories(): array
    {
        return SupportCategory::cases();
    }
}
