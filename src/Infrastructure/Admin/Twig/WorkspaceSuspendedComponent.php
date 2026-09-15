<?php

declare(strict_types=1);

namespace App\Infrastructure\Admin\Twig;

use App\Application\Workspace\DTO\Request\SuspendWorkspaceRequest;
use App\Application\Workspace\UseCase\ReactivateWorkspaceUseCase;
use App\Application\Workspace\UseCase\SuspendWorkspaceUseCase;
use App\Domain\User\Repository\AdminRepositoryInterface;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Repository\WorkspaceRepositoryInterface;
use App\Infrastructure\Workspace\Form\SuspendedWorkspaceType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Webmozart\Assert\Assert;

#[AsLiveComponent(
    name: 'WorkspaceSuspendedComponent',
    template: 'components/Admin/Workspace/WorkspaceSuspendedComponent.html.twig',
    route: 'admin_ux_live_component',
)]
class WorkspaceSuspendedComponent extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public ?string $slugId = null;

    #[LiveProp]
    public ?string $adminEmail = null;

    public function __construct(
        private readonly WorkspaceRepositoryInterface $workspaceRepository,
        private readonly LoggerInterface $logger,
        private readonly SuspendWorkspaceUseCase $suspendWorkspaceUseCase,
        private readonly ReactivateWorkspaceUseCase $reactivateWorkspaceUseCase,
        private readonly AdminRepositoryInterface $adminRepository,
    ) {
    }

    private function getWorkspace(): Workspace
    {
        Assert::notNull($this->slugId);

        return $this->workspaceRepository->getBySlug($this->slugId);
    }

    public function isWorkspaceActive(): bool
    {
        return $this->getWorkspace()->isActive;
    }

    protected function instantiateForm(): FormInterface
    {
        Assert::notNull($this->adminEmail);
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);
        // On délègue l'hydratation au DTO pour garder le composant propre
        $dto = SuspendWorkspaceRequest::fromEntity($this->getWorkspace(), $admin);

        return $this->createForm(SuspendedWorkspaceType::class, $dto);
    }

    #[LiveAction]
    public function save(): RedirectResponse
    {
        $this->submitForm();

        /** @var SuspendWorkspaceRequest $dto */
        $dto = $this->getForm()->getData();

        try {
            ($this->suspendWorkspaceUseCase)($this->getWorkspace(), $dto);

            $this->addFlash(
                'success',
                'Le cabinet à bien été suspendu'
            );
        } catch (\DomainException $e) {
            // 🛡️ Safe to expose : Les erreurs métier sont destinées à l'utilisateur
            $this->addFlash('error', $e->getMessage());

            $this->logger->warning('[Workspace] Rejet métier lors de l\'édition', [
                'workspace_slug' => $this->slugId,
                'reason' => $e->getMessage(),
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);

            // En cas d'erreur métier, on ne redirige pas, on laisse le composant afficher l'erreur
            return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
        } catch (\Throwable $e) {
            // 🛡️ SECOPS STRICT : On masque l'erreur technique au client final !
            $this->addFlash(
                'error',
                'Une erreur technique est survenue. Veuillez réessayer plus tard.',
            );

            $this->logger->critical('[Workspace] Crash critique lors de la modification des données légales', [
                'workspace_slug' => $this->slugId,
                'user' => $this->getUser()?->getUserIdentifier(),
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Action de redirection UX (force un rechargement de page complet)
        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }

    #[LiveAction]
    public function reactivate(): RedirectResponse
    {
        Assert::notNull($this->adminEmail);
        $admin = $this->adminRepository->findByEmail($this->adminEmail);
        Assert::notNull($admin);

        try {
            ($this->reactivateWorkspaceUseCase)($this->getWorkspace(), $admin->getUserIdentifier(), trim($admin->getFullName()));

            $this->addFlash('success', 'Le cabinet a bien été réactivé.');
        } catch (\Throwable $e) {
            $this->addFlash(
                'error',
                'Une erreur technique est survenue. Veuillez réessayer plus tard.',
            );

            $this->logger->critical('[Workspace] Crash critique lors de la réactivation du cabinet', [
                'workspace_slug' => $this->slugId,
                'user' => $this->getUser()?->getUserIdentifier(),
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->redirectToRoute('admin_workspace_details', ['slugId' => $this->slugId]);
    }
}
