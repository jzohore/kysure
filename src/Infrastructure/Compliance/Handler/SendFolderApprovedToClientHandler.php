<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Handler;

use App\Infrastructure\Compliance\Message\SendFolderApprovedToClientMessage;
use App\Infrastructure\Notification\Email\Client\FolderApprovedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendFolderApprovedToClientHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendFolderApprovedToClientMessage $message): void
    {
        $this->mailer->send(new FolderApprovedEmail(
            email: $message->clientEmail,
            workspaceName: $message->workspaceName,
            workspaceContactEmail: $message->workspaceContactEmail,
            folderReference: $message->folderReference,
            portalUrl: $message->portalUrl,
        ));
    }
}
