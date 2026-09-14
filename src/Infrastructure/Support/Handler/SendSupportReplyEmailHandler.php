<?php

declare(strict_types=1);

namespace App\Infrastructure\Support\Handler;

use App\Domain\Support\Repository\SupportThreadRepositoryInterface;
use App\Infrastructure\Notification\Email\Support\SupportReplyNotificationEmail;
use App\Infrastructure\Support\Message\SendSupportReplyEmailMessage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Webmozart\Assert\Assert;

#[AsMessageHandler]
final readonly class SendSupportReplyEmailHandler
{
    public function __construct(
        private SupportThreadRepositoryInterface $threadRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(SendSupportReplyEmailMessage $message): void
    {
        $thread = $this->threadRepository->findById(Uuid::fromString($message->threadId));
        Assert::notNull($thread, 'Le ticket de support est introuvable pour l\'envoi de l\'email de réponse.');

        $email = new SupportReplyNotificationEmail(
            recipientEmail: $thread->user->email,
            recipientFirstName: $thread->user->firstName,
            ticketTitle: sprintf('%s · %s', $thread->getCategoryTitle(), $thread->getTopicTitle()),
            ticketUrl: $message->ticketUrl,
        );

        $this->mailer->send($email);
    }
}
