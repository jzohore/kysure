<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Twig\Components;

use App\Application\Workspace\UseCase\Onboarding\BindWorkspaceTypeUseCase;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Exception\WorkspaceTypeNotFoundException;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'WorkspaceChoosePlanFormComponent',
    template: 'components/Workspace/WorkspaceChoosePlanFormComponent.html.twig',
)]
class WorkspaceChoosePlanFormComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $workspaceType = 'individual';

    public function __construct(
        private readonly CurrentWorkspaceProvider $workspaceProvider,
        private readonly CurrentUserProvider $currentUserProvider,
        private readonly BindWorkspaceTypeUseCase $bindWorkspaceTypeUseCase,
        private readonly UrlGeneratorInterface $generator,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[LiveAction]
    public function finish(): RedirectResponse // ✅ Plus précis que Response
    {
        $workspaceTypeEnum = WorkspaceType::tryFrom($this->workspaceType);

        if (!$workspaceTypeEnum) {
            throw WorkspaceTypeNotFoundException::withWorkspaceType($this->workspaceType);
        }

        try {
            $user = $this->currentUserProvider->getUser();
            $workspace = $this->workspaceProvider->getWorkspace();

            ($this->bindWorkspaceTypeUseCase)(
                workspaceType: $workspaceTypeEnum,
                user: $user,
                workspace: $workspace,
            );

            return new RedirectResponse($this->generator->generate('app_onboarding_regulatory_profile'));
        } catch (\Exception $e) {
            $this->logger->error('Échec lors de l\'attribution du plan au Workspace : ', [
                'error' => $e->getMessage(),
                'workspace_type' => $this->workspaceType,
            ]);

            /** @var FlashBagAwareSessionInterface $session */
            $session = $this->requestStack->getSession();
            $session->getFlashBag()->add(
                type: 'error',
                message: 'Une erreur est survenue lors de la validation de votre plan. Veuillez réessayer.'
            );

            return new RedirectResponse($this->generator->generate('app_onboarding_plan'));
        }
    }
}
