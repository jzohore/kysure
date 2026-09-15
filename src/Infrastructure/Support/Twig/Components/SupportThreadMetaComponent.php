<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Twig\Components;

use App\Application\Support\UseCase\AssignSupportThreadUseCase;
use App\Application\Support\UseCase\ChangeSupportThreadPriorityUseCase;
use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportPriority;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Repository\AdminRepositoryInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Assignation à un opérateur et priorité d'un ticket — affiché dans la
 * sidebar de la page de détail, à côté du chat.
 */
#[AsLiveComponent(
    name: 'SupportThreadMetaComponent',
    template: 'components/Support/SupportThreadMetaComponent.html.twig',
    route: 'admin_ux_live_component',
)]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SupportThreadMetaComponent
{
    use DefaultActionTrait;

    #[LiveProp]
    public SupportThread $thread;

    #[LiveProp(writable: true)]
    public string $selectedAdminId = '';

    #[LiveProp(writable: true)]
    public string $selectedPriority = '';

    public function __construct(
        private readonly AssignSupportThreadUseCase $assignUseCase,
        private readonly ChangeSupportThreadPriorityUseCase $changePriorityUseCase,
        private readonly AdminRepositoryInterface $adminRepository,
    ) {
    }

    public function mount(SupportThread $thread): void
    {
        $this->thread = $thread;
        $this->selectedAdminId = $thread->assignedTo?->id?->toRfc4122() ?? '';
        $this->selectedPriority = $thread->priority->value;
    }

    /**
     * @return Admin[]
     */
    public function getAvailableAdmins(): array
    {
        return $this->adminRepository->findAllActive();
    }

    /**
     * @return SupportPriority[]
     */
    public function getAvailablePriorities(): array
    {
        return SupportPriority::cases();
    }

    #[LiveAction]
    public function assign(): void
    {
        $admin = '' === $this->selectedAdminId ? null : $this->adminRepository->findById($this->selectedAdminId);
        $this->assignUseCase->execute($this->thread, $admin);
    }

    #[LiveAction]
    public function changePriority(): void
    {
        $this->changePriorityUseCase->execute($this->thread, SupportPriority::from($this->selectedPriority));
    }
}
