<?php

declare(strict_types=1);

namespace App\Application\User\UseCase;

use App\Application\User\DTO\Request\LoginUserRequest;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\User\Enum\UserType;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Repository\AdminRepositoryInterface;
use App\Domain\User\Repository\ClientRepositoryInterface;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Infrastructure\User\Message\SendMagicLinkMessage;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final readonly class SendLoginUserUseCase
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private MessageBusInterface $messageBus,
        private ClientRepositoryInterface $clientRepository,
        private AdminRepositoryInterface $adminRepository,
        #[Target('securityLogger')]
        private LoggerInterface $securityLogger, // 🛡️ SECOPS : Journal d'audit dédié
    ) {
    }

    /**
     * @throws ExceptionInterface|RandomException|UserNotFoundException
     */
    public function __invoke(LoginUserRequest $request): void
    {
        $email = strtolower(trim($request->email));
        Assert::email($email, 'Le format de l\'adresse email est invalide.');

        $this->securityLogger->info('Tentative de connexion Magic Link initiée', [
            'email' => $email,
            // 'ip' => $request->ipAddress // 💡 Recommandé : Passer l'IP dans le DTO depuis le contrôleur
        ]);

        $user = $this->userRepository->findByEmail($email);
        if ($user instanceof User) {
            $this->handleCgpLogin($user);

            return;
        }

        $client = $this->clientRepository->findByEmail($email);
        if ($client instanceof Client) {
            $this->handleClientLogin($client);

            return;
        }

        $admin = $this->adminRepository->findByEmail($email);
        if ($admin instanceof Admin) {
            $this->handleAdminLogin($admin);

            return;
        }

        $this->securityLogger->warning('Tentative de connexion échouée : email introuvable', [
            'email' => $email,
        ]);

        // Cette exception doit être "catchée" par le contrôleur pour renvoyer un message de succès générique.
        throw UserNotFoundException::withEmail($email);
    }

    /**
     * @throws RandomException
     * @throws ExceptionInterface
     */
    private function handleCgpLogin(User $user): void
    {
        $user->clearMagicLinkToken();
        $user->generateMagicLinkToken();

        $this->userRepository->save($user);

        $token = $user->magicLinkToken;
        Assert::stringNotEmpty($token, 'Le token Magic Link CGP n\'a pas pu être généré.');

        $this->messageBus->dispatch(new SendMagicLinkMessage(
            email: $user->email,
            magicLinkToken: $token,
            recipientType: UserType::CGP
        ));

        $this->securityLogger->info('Magic Link généré et dispatché', ['type' => 'CGP', 'user_id' => $user->id?->toString()]);
    }

    /**
     * @throws RandomException
     * @throws ExceptionInterface
     */
    private function handleClientLogin(Client $client): void
    {
        $client->clearMagicLinkToken();
        $client->generateMagicLinkToken();

        $this->clientRepository->save($client);

        $token = $client->magicLinkToken;
        Assert::stringNotEmpty($token, 'Le token Magic Link Client n\'a pas pu être généré.');

        $this->messageBus->dispatch(new SendMagicLinkMessage(
            email: $client->email,
            magicLinkToken: $token,
            recipientType: UserType::CLIENT
        ));

        $this->securityLogger->info('Magic Link généré et dispatché', ['type' => 'CLIENT', 'client_id' => $client->id?->toString()]);
    }

    /**
     * @throws RandomException
     * @throws ExceptionInterface
     */
    private function handleAdminLogin(Admin $admin): void
    {
        $admin->clearMagicLinkToken();
        $admin->generateMagicLinkToken();

        $this->adminRepository->save($admin);

        $token = $admin->magicLinkToken;
        Assert::stringNotEmpty($token, 'Le token Magic Link Admin n\'a pas pu être généré.');

        $this->messageBus->dispatch(new SendMagicLinkMessage(
            email: $admin->email,
            magicLinkToken: $token,
            recipientType: UserType::ADMIN
        ));

        $this->securityLogger->info('Magic Link généré et dispatché', ['type' => 'ADMIN', 'admin_id' => $admin->id?->toString()]);
    }
}
