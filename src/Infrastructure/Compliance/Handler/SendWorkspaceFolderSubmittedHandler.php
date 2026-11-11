<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Handler;

use App\Infrastructure\Compliance\Message\DispatchNotifyFolderSubmitted;
use App\Infrastructure\Notification\Email\Compliance\WorkspaceFolderSubmittedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendWorkspaceFolderSubmittedHandler
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(DispatchNotifyFolderSubmitted $message): void
    {
        $this->mailer->send(new WorkspaceFolderSubmittedEmail(
            email: $message->recipientEmail,
            clientName: $message->clientName,
            folderReference: $message->folderReference,
            reviewUrl: $message->reviewUrl,
        ));
    }
}
