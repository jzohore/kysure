<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\Entity\Admin;
use App\Domain\User\Entity\Client;
use App\Domain\User\Repository\AdminRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * @see https://symfony.com/doc/current/security/custom_authenticator.html
 */
class MagicAdminLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminRepositoryInterface $adminRepository,
    ) {
    }

    /**
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): ?bool
    {
        return 'admin_verify_magic_link' === $request->attributes->get('_route')
            && $request->attributes->has('token');
    }

    // src/Security/MagicLinkAuthenticator.php (méthode authenticate)

    public function authenticate(Request $request): Passport
    {
        $token = $request->attributes->get('token');
        if (!$token) {
            throw new AuthenticationException('Jeton de connexion manquant.');
        }

        // Le jeton est le seul identifiant. On charge l'utilisateur via le jeton.
        // NOTE : Cela nécessite que votre UserProvider gère la recherche par jeton
        // ou que vous le fassiez manuellement via un service/repository.

        // On utilise un CustomUserProvider (similaire au UserBadge mais par Token)
        $userLoader = function (string $token): Admin {
            // --- C'est ici que l'utilisateur est chargé par le jeton ---
            $user = $this->adminRepository->findByMagicLink($token);

            if (!$user instanceof Admin) {
                throw new AuthenticationException('Jeton de connexion invalide ou déjà utilisé.');
            }

            return $user;
        };

        $tokenBadge = new CustomCredentials(
            static function ($credentials, UserInterface $user): bool {
                /** @var Client $user */
                // 1. Le jeton correspond (double vérification, car il est déjà chargé par le jeton)
                if ($credentials !== $user->magicLinkToken) {
                    return false;
                }

                // 2. CORRECTION CRITIQUE de la LOGIQUE D'EXPIRATION
                // Le jeton a expiré ou est invalide
                return $user->isMagicLinkTokenValid();
            },
            $token
        );

        return new Passport(
            new UserBadge($token, $userLoader),
            $tokenBadge,
            []
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?RedirectResponse
    {
        /** @var Admin $user */
        $user = $token->getUser();
        // 1. DÉTRUIRE LE JETON (Vital en sécurité !)
        // Assure-toi d'avoir une méthode dans ton entité pour nullifier le jeton
        $user->clearMagicLinkToken();
        $this->adminRepository->save($user); // (ou via l'EntityManager)

        if ($request->hasSession()) {
            // On inscrit en dur dans la session la route finale.
            // Quand Scheb 2FA aura fini son travail, il lira cette variable !
            $request->getSession()->set(
                '_security.' . $firewallName . '.target_path',
                $this->urlGenerator->generate('admin_dashboard')
            );
        }

        // Si le 2FA N'EST PAS actif, cette redirection s'exécute normalement.
        // Si le 2FA EST actif, Scheb intercepte et l'ignore, mais notre session est sauvegardée.
        return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    // public function start(Request $request, ?AuthenticationException $authException = null): Response
    // {
    //     /*
    //      * If you would like this class to control what happens when an anonymous user accesses a
    //      * protected page (e.g. redirect to /login), uncomment this method and make this class
    //      * implement Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface.
    //      *
    //      * For more details, see https://symfony.com/doc/current/security/experimental_authenticators.html#configuring-the-authentication-entry-point
    //      */
    // }
}
