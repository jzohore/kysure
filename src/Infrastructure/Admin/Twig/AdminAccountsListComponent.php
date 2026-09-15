<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Domain\User\Entity\Admin;
use App\Domain\User\Enum\AdminRole;
use App\Domain\User\Repository\AdminRepositoryInterface;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste des comptes de l'équipe KYSURE (back-office). Filtres : recherche
 * (e-mail / nom) et statut. Réservé au ROLE_SUPER_ADMIN (garde sur le contrôleur
 * hôte et l'access_control).
 */
#[AsLiveComponent(
    name: 'AdminAccountsListComponent',
    template: 'components/Admin/Account/AdminAccountsListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminAccountsListComponent
{
    use DefaultActionTrait;

    private const int PER_PAGE = 25;

    /** @var list<string> */
    private const array STATUSES = ['all', 'active', 'suspended', 'archived'];

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $status = 'all';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private readonly AdminRepositoryInterface $adminRepository,
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
        $this->status = 'all';
        $this->page = 1;
    }

    public function hasFilters(): bool
    {
        return '' !== $this->search || 'all' !== $this->status;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function statusOptions(): array
    {
        $labels = [
            'all' => 'Tous les statuts',
            'active' => 'Actifs',
            'suspended' => 'Suspendus',
            'archived' => 'Archivés',
        ];

        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $labels[$value]],
            self::STATUSES,
        );
    }

    public function primaryRole(Admin $admin): AdminRole
    {
        foreach ($admin->getRoles() as $role) {
            $enum = AdminRole::tryFrom($role);
            if (null !== $enum) {
                return $enum;
            }
        }

        return AdminRole::OPERATOR;
    }

    /**
     * @return Pagerfanta<Admin>
     */
    public function getAdmins(): Pagerfanta
    {
        $status = in_array($this->status, self::STATUSES, true) ? $this->status : 'all';

        return $this->adminRepository->paginateForList(
            page: max(1, $this->page),
            perPage: self::PER_PAGE,
            search: '' !== $this->search ? $this->search : null,
            status: $status,
        );
    }
}
