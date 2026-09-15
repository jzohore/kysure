<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Application\Billing\Provider\MrrProviderInterface;
use App\Domain\Billing\Entity\Subscription;
use App\Domain\Billing\Enum\SubscriptionStatus;
use App\Domain\Billing\Repository\SubscriptionRepositoryInterface;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste des abonnements (tous cabinets) pour le back-office KYSURE.
 */
#[AsLiveComponent(
    name: 'AdminSubscriptionsListComponent',
    template: 'components/Admin/Subscription/AdminSubscriptionsListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminSubscriptionsListComponent
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
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly MrrProviderInterface $mrrProvider,
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
     * @return array<int, SubscriptionStatus>
     */
    public function statuses(): array
    {
        return SubscriptionStatus::cases();
    }

    /** MRR Stripe (mis en cache ~15 min par le provider). */
    public function mrrEuros(): float
    {
        return $this->mrrProvider->getCurrentMrr();
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        return $this->subscriptionRepository->countGroupedByStatus();
    }

    /**
     * @return Pagerfanta<Subscription>
     */
    public function getSubscriptions(): Pagerfanta
    {
        return $this->subscriptionRepository->getPaginatedSubscriptions(
            page: max(1, $this->page),
            perPage: self::PER_PAGE,
            search: '' !== $this->search ? $this->search : null,
            status: SubscriptionStatus::tryFrom($this->status),
        );
    }
}
