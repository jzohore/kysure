<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Handler;

use App\Infrastructure\Compliance\Message\SendElectronicDeliveryConsentRequestMessage;
use App\Infrastructure\Notification\Email\Client\ElectronicDeliveryConsentRequestEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendElectronicDeliveryConsentRequestHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendElectronicDeliveryConsentRequestMessage $message): void
    {
        $this->mailer->send(new ElectronicDeliveryConsentRequestEmail(
            email: $message->clientEmail,
            workspaceName: $message->workspaceName,
            workspaceContactEmail: $message->workspaceContactEmail,
            portalUrl: $message->portalUrl,
        ));
    }
}
