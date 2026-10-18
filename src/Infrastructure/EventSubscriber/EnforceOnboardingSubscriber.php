<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use App\Domain\User\Entity\User;
use App\Domain\User\Enum\OnboardingStatus;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

final readonly class EnforceOnboardingSubscriber implements EventSubscriberInterface
{
    private const array ALLOWED_ROUTES = [
        'app_logout',               // Il faut toujours laisser le droit de se déconnecter !
        'app_login',                // Si jamais il tombe ici
        'app_register',
        'ux_live_component',
        'app_onboarding_completed',
    ];

    public function __construct(
        private Security $security,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // On écoute TOUTES les requêtes HTTP.
            // Priorité 0 : On s'assure de passer APRÈS le Firewall de Symfony (qui a une priorité de 8)
            // pour être sûr que l'utilisateur est bien identifié en session.
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $currentRoute = $request->attributes->get('_route');

        // On laisse passer les requêtes techniques de Symfony et les Live Components
        if (is_string($currentRoute) && str_starts_with($currentRoute, '_')) {
            return;
        }

        // On laisse passer les routes de sécurité (Login, Logout)
        if (in_array($currentRoute, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $allowedRoutesForStatus = match ($user->onboardingStatus) {
            OnboardingStatus::PENDING => [
                'app_onboarding_workspace',
                'app_onboarding_workspace_manual_config',
            ],
            OnboardingStatus::WORKSPACE_SETUP => [
                'app_onboarding_plan',
            ],
            OnboardingStatus::PLAN_SETUP => [
                'app_onboarding_regulatory_profile',
            ],
            OnboardingStatus::REGULATORY_PROFILE_SETUP => [
                'app_onboarding_finalization',
            ],
            default => [], // Tableau vide = onboarding terminé, accès libre
        };

        if ([] !== $allowedRoutesForStatus && !in_array($currentRoute, $allowedRoutesForStatus, true)) {
            // On le redirige par défaut vers la PREMIÈRE route du tableau (la route principale)
            $defaultRoute = $allowedRoutesForStatus[0];

            $url = $this->router->generate($defaultRoute);
            $event->setResponse(new RedirectResponse($url));
            $event->stopPropagation();

            return;
        }
    }
}
