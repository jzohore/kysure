<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Application\Workspace\UseCase\Admin\GrantWorkspaceQuotaUseCase;
use App\Domain\User\Repository\AdminRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

/**
 * Recharge admin ponctuelle (geste commercial) : dossiers d'essai et/ou
 * minutes d'entretien crédités à un cabinet. Motif obligatoire, tracé en
 * audit par {@see GrantWorkspaceQuotaUseCase}.
 *
 * Redirige après action (comme WorkspaceEditComponent / WorkspaceSuspendedComponent) :
 * la page admin_workspace_details affiche le quota restant en dehors de ce
 * composant, un simple re-render du composant ne le rafraîchirait pas.
 */
#[AsLiveComponent(
    name: 'GrantWorkspaceQuotaComponent',
    template: 'components/Admin/Workspace/GrantWorkspaceQuotaComponent.html.twig',
)]
class GrantWorkspaceQuotaComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $slugId = '';

    #[LiveProp]
    public string $adminEmail = '';

    #[LiveProp(writable: true)]
    public int $dossiers = 0;

    #[LiveProp(writable: true)]
    public int $minutes = 0;

    #[LiveProp(writable: true)]
    public string $reason = '';

    public function __construct(
        private readonly GrantWorkspaceQuotaUseCase $grantWorkspaceQuotaUseCase,
        private readonly AdminRepositoryInterface $adminRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[LiveAction]
    public function grant(): RedirectResponse
    {
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->grantWorkspaceQuotaUseCase)(
                workspaceSlugId: $this->slugId,
                dossiers: $this->dossiers,
                minutes: $this->minutes,
                reason: $this->reason,
                actorName: trim($admin->getFullName()),
                actorSlugId: $admin->slugId,
            );

            $this->addFlash('success', 'Quota crédité avec succès.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            $this->logger->warning('[Workspace] Rejet métier lors de la recharge de quota.', [
                'workspace_slug' => $this->slugId,
                'reason' => $e->getMessage(),
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Une erreur technique est survenue. Veuillez réessayer plus tard.');

            $this->logger->critical('[Workspace] Crash critique lors de la recharge de quota', [
                'workspace_slug' => $this->slugId,
                'user' => $this->getUser()?->getUserIdentifier(),
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }
}
