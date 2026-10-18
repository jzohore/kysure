<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Onboarding;

use App\Domain\Billing\Enum\Plan;
use App\Domain\Workspace\Enum\WorkspaceType;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

#[AsController]
#[Route(path: '/app/onboarding/finalization', name: 'app_onboarding_finalization', methods: ['GET'])]
final readonly class OnBoardingFinalizationController
{
    public function __construct(
        private CurrentWorkspaceProvider $currentWorkspaceProvider,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
    ): Response {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();

        return new Response(
            $twig->render('@app/onboarding/finalization.html.twig', [
                'workspace_name' => $workspace->name,
                'account_type_label' => Plan::forWorkspaceType($workspace->isFirm() ? WorkspaceType::FIRM : WorkspaceType::INDIVIDUAL)->getLabel(),
                // Profil reporté ou incomplet : on le rappelle sur l'écran de fin (DER indisponible tant que non complété).
                'regulatory_profile_complete' => $workspace->regulatoryProfile?->isProfileValid() ?? false,
                // Dotation d'essai réellement restante (pas la valeur de départ) ; masquée avec un abonnement actif.
                'trial_dossiers' => $workspace->hasActiveSubscription() ? null : $workspace->trialDossiersRemaining,
                'trial_minutes' => $workspace->hasActiveSubscription() ? null : $workspace->remainingMeetingMinutes(),
            ])
        );
    }
}
