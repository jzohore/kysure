<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use Pagerfanta\Pagerfanta;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Journal d'audit inter-cabinets du back-office KYSURE : tous les événements de
 * tous les cabinets, filtrables par cabinet, type d'événement et intervalle de
 * dates.
 */
#[AsLiveComponent(
    name: 'AdminAuditLogsListComponent',
    template: 'components/Admin/AuditLog/AdminAuditLogsListComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class AdminAuditLogsListComponent
{
    use DefaultActionTrait;

    private const int PER_PAGE = 25;

    #[LiveProp(writable: true)]
    public string $workspace = '';

    #[LiveProp(writable: true)]
    public string $eventType = '';

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    #[LiveProp(writable: true)]
    public string $actor = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private readonly AuditLogRepositoryInterface $auditLogRepository,
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
        $this->workspace = '';
        $this->eventType = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->actor = '';
        $this->page = 1;
    }

    public function hasFilters(): bool
    {
        return '' !== $this->workspace
            || '' !== $this->eventType
            || '' !== $this->dateFrom
            || '' !== $this->dateTo
            || '' !== $this->actor;
    }

    /**
     * @return array<int, AuditEventType>
     */
    public function eventTypes(): array
    {
        $types = AuditEventType::cases();
        usort($types, static fn (AuditEventType $a, AuditEventType $b): int => $a->getLabel() <=> $b->getLabel());

        return $types;
    }

    /**
     * @return Pagerfanta<AuditLog>
     */
    public function getLogs(): Pagerfanta
    {
        return $this->auditLogRepository->getGlobalAuditLogsList(
            page: max(1, $this->page),
            perPage: self::PER_PAGE,
            workspaceQuery: '' !== $this->workspace ? $this->workspace : null,
            eventType: AuditEventType::tryFrom($this->eventType),
            from: $this->parseDate($this->dateFrom),
            to: $this->parseDate($this->dateTo, endOfDay: true),
            actorQuery: '' !== $this->actor ? $this->actor : null,
        );
    }

    private function parseDate(string $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);
    }
}
