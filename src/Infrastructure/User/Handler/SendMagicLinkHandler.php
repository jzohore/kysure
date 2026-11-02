<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Handler;

use App\Domain\Shared\Service\GenerateLinkToken;
use App\Domain\User\Entity\Admin;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\User;
use App\Domain\User\Enum\UserType;
use App\Domain\User\Repository\AdminRepositoryInterface;
use App\Domain\User\Repository\ClientRepositoryInterface;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Infrastructure\Notification\Email\MagicLinkEmail;
use App\Infrastructure\User\Message\SendMagicLinkMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendMagicLinkHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ClientRepositoryInterface $clientRepository,
        private AdminRepositoryInterface $adminRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private GenerateLinkToken $generateLinkToken,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function __invoke(SendMagicLinkMessage $message): void
    {
        $email = strtolower(trim($message->email));
        $recipientType = $message->recipientType;

        $recipientExists = match ($recipientType) {
            UserType::CLIENT => $this->clientRepository->findByEmail($email) instanceof Client,
            UserType::CGP => $this->userRepository->findByEmail($email) instanceof User,
            UserType::ADMIN => $this->adminRepository->findByEmail($email) instanceof Admin,
        };

        if (!$recipientExists) {
            $this->logger->warning('SendMagicLinkHandler: Destinataire introuvable en BDD, abandon de l\'envoi.', [
                'email' => $email,
                'recipient_type' => $recipientType,
            ]);

            return;
        }

        $routeName = match ($recipientType) {
            UserType::CLIENT => 'portal_verify_magic_link',
            UserType::CGP => 'app_verify_magic_link',
            UserType::ADMIN => 'admin_verify_magic_link',
        };

        $magicLinkUrl = $this->generateLinkToken->generate(
            routeName: $routeName,
            magicLinkToken: $message->magicLinkToken
        );

        $emailObject = new MagicLinkEmail(
            email: $email,
            actionUrl: $magicLinkUrl,
        );

        $this->mailer->send($emailObject);

        $this->logger->info('Lien magique envoyé avec succès.', [
            'email' => $email,
            'recipient_type' => $recipientType,
        ]);
    }
}
