<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Handler;

use App\Infrastructure\AdequacyReport\Message\SendAdequacyReportAvailableMessage;
use App\Infrastructure\Notification\Email\Client\AdequacyReportAvailableEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendAdequacyReportAvailableHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendAdequacyReportAvailableMessage $message): void
    {
        $this->mailer->send(new AdequacyReportAvailableEmail(
            email: $message->clientEmail,
            workspaceName: $message->workspaceName,
            workspaceContactEmail: $message->workspaceContactEmail,
            portalUrl: $message->portalUrl,
            version: $message->version,
        ));
    }
}
