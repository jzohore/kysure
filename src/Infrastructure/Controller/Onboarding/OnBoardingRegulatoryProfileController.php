<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Onboarding;

use App\Domain\User\Entity\User;
use App\Domain\User\Enum\OnboardingStatus;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Webmozart\Assert\Assert;

/**
 * Étape « profil réglementaire » de l'onboarding : après le choix du type de compte,
 * avant la préparation du tableau de bord.
 */
#[AsController]
#[Route(path: '/app/onboarding/regulatory-profile', name: 'app_onboarding_regulatory_profile', methods: ['GET'])]
final readonly class OnBoardingRegulatoryProfileController
{
    public function __construct(
        private CurrentWorkspaceProvider $currentWorkspaceProvider,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function __invoke(
        Environment $twig,
        #[CurrentUser]
        User $user,
    ): Response {
        // Hors de cette étape : on laisse EnforceOnboardingSubscriber réorienter (ou le tableau de bord).
        if (OnboardingStatus::PLAN_SETUP !== $user->onboardingStatus) {
            return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
        }

        $profile = $this->currentWorkspaceProvider->getWorkspace()->regulatoryProfile;
        Assert::notNull($profile, 'Le profil réglementaire est créé avec le cabinet.');

        return new Response(
            $twig->render('@app/onboarding/regulatory_profile.html.twig', [
                'regulatory_profile_id' => (string) $profile->id,
            ])
        );
    }
}
