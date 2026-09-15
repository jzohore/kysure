<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Vue opérateur des dossiers de conformité, tous cabinets confondus.
 */
#[AsLiveComponent(
    name: 'AdminComplianceFoldersListComponent',
    template: 'components/Admin/Compliance/AdminComplianceFoldersListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminComplianceFoldersListComponent
{
    use DefaultActionTrait;

    private const int PER_PAGE = 25;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private readonly ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    #[LiveAction]
    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    #[LiveAction]
    public function nextPage(): void
    {
        ++$this->page;
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->page = 1;
    }

    public function hasFilters(): bool
    {
        return '' !== $this->search || '' !== $this->status;
    }

    /**
     * @return array<int, ComplianceFolderStatus>
     */
    public function statuses(): array
    {
        $statuses = ComplianceFolderStatus::cases();
        usort($statuses, static fn (ComplianceFolderStatus $a, ComplianceFolderStatus $b): int => $a->getLabel() <=> $b->getLabel());

        return $statuses;
    }

    /**
     * @return Pagerfanta<ComplianceFolder>
     */
    public function getFolders(): Pagerfanta
    {
        return $this->folderRepository->findAllForAdmin(
            page: max(1, $this->page),
            perPage: self::PER_PAGE,
            search: '' !== $this->search ? $this->search : null,
            status: ComplianceFolderStatus::tryFrom($this->status),
        );
    }
}
