<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Application\Billing\UseCase\Admin\AdminCancelSubscriptionCancellationUseCase;
use App\Application\Billing\UseCase\Admin\AdminCancelSubscriptionUseCase;
use App\Application\Billing\UseCase\Admin\AdminForceSyncSubscriptionUseCase;
use App\Application\Billing\UseCase\Admin\AdminPauseSubscriptionUseCase;
use App\Application\Billing\UseCase\Admin\AdminResumeSubscriptionUseCase;
use App\Application\Billing\UseCase\Admin\AdminUpdateSubscriptionSeatsUseCase;
use App\Domain\Billing\Enum\Plan;
use App\Domain\User\Repository\AdminRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use App\Domain\Workspace\Service\SeatAvailability;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Pilotage de l'abonnement Stripe d'un cabinet depuis le back-office : sièges,
 * pause/reprise, résiliation programmée, resynchronisation forcée. Chaque
 * action retourne sur admin_workspace_details (rechargement complet), comme
 * les autres composants de gestion de cette page.
 *
 * Action à impact financier direct sur le client (Stripe) : réservée à
 * ROLE_SUPER_ADMIN, contrairement au reste du back-office ouvert à
 * ROLE_ADMIN (Opérateur). Voir la convention actée lors du passage aux
 * deux rôles back-office.
 */
#[AsLiveComponent(
    name: 'ManageSubscriptionComponent',
    template: 'components/Admin/Workspace/ManageSubscriptionComponent.html.twig',
    route: 'admin_ux_live_component',
)]
#[IsGranted('ROLE_SUPER_ADMIN')]
class ManageSubscriptionComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $slugId = '';

    #[LiveProp]
    public string $adminEmail = '';

    #[LiveProp(writable: true)]
    public ?int $seats = null;

    #[LiveProp(writable: true)]
    public string $cancelReason = '';

    public function __construct(
        private readonly WorkspaceRepositoryInterface $workspaceRepository,
        private readonly AdminRepositoryInterface $adminRepository,
        private readonly AdminUpdateSubscriptionSeatsUseCase $updateSeatsUseCase,
        private readonly SeatAvailability $seatAvailability,
        private readonly AdminPauseSubscriptionUseCase $pauseUseCase,
        private readonly AdminResumeSubscriptionUseCase $resumeUseCase,
        private readonly AdminCancelSubscriptionUseCase $cancelUseCase,
        private readonly AdminCancelSubscriptionCancellationUseCase $cancelSubscriptionCancellationUseCase,
        private readonly AdminForceSyncSubscriptionUseCase $forceSyncUseCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function getWorkspace(): Workspace
    {
        Assert::notNull($this->slugId);

        return $this->workspaceRepository->getBySlug($this->slugId);
    }

    public function getCurrentSeats(): int
    {
        return $this->getWorkspace()->subscription->seatsCount ?? 1;
    }

    /** Sièges déjà occupés (membres actifs + invitations en attente) : plancher indépassable. */
    public function getUsedSeats(): int
    {
        return $this->seatAvailability->usedSeats($this->getWorkspace());
    }

    /** Plancher réel du champ : usage actuel ou minimum de l'offre, le plus élevé des deux. */
    public function getSeatsFloor(): int
    {
        $workspace = $this->getWorkspace();
        $minPlanSeats = Plan::forWorkspaceType($workspace->isFirm() ? WorkspaceType::FIRM : WorkspaceType::INDIVIDUAL)->getMinSeats();

        return max($minPlanSeats, $this->getUsedSeats());
    }

    public function hasActiveSubscription(): bool
    {
        return null !== $this->getWorkspace()->subscription?->stripeSubscriptionId;
    }

    public function isPaused(): bool
    {
        return $this->getWorkspace()->subscription?->isPaused() ?? false;
    }

    public function isCancelPending(): bool
    {
        return $this->getWorkspace()->subscription->cancelAtPeriodEnd ?? false;
    }

    #[LiveAction]
    public function updateSeats(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->updateSeatsUseCase)(
                $this->getWorkspace(),
                $this->seats ?? $this->getCurrentSeats(),
                $admin->getUserIdentifier(),
                trim($admin->getFullName()),
            );
            $this->addFlash('success', 'Nombre de sièges mis à jour.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de l\'ajustement des sièges (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function pause(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->pauseUseCase)($this->getWorkspace(), $admin->getUserIdentifier(), trim($admin->getFullName()));
            $this->addFlash('success', 'Abonnement suspendu.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de la suspension de l\'abonnement (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function resume(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->resumeUseCase)($this->getWorkspace(), $admin->getUserIdentifier(), trim($admin->getFullName()));
            $this->addFlash('success', 'Abonnement repris.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de la reprise de l\'abonnement (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function cancel(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->cancelUseCase)($this->getWorkspace(), $this->cancelReason, $admin->getUserIdentifier(), trim($admin->getFullName()));
            $this->addFlash('success', 'Résiliation programmée en fin de période.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de la résiliation de l\'abonnement (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function undoCancel(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->cancelSubscriptionCancellationUseCase)($this->getWorkspace(), $admin->getUserIdentifier(), trim($admin->getFullName()));
            $this->addFlash('success', 'Résiliation programmée annulée, l\'abonnement continue.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de l\'annulation de la résiliation (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function forceSync(): RedirectResponse
    {
        try {
            ($this->forceSyncUseCase)($this->getWorkspace());
            $this->addFlash('success', 'Abonnement resynchronisé avec Stripe.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');
            $this->logger->critical('[Billing] Crash lors de la resynchronisation Stripe (admin).', [
                'workspace_slug' => $this->slugId,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }
}
