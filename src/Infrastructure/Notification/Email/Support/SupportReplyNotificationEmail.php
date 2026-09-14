<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Support;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

final class SupportReplyNotificationEmail extends TemplatedEmail
{
    public function __construct(
        string $recipientEmail,
        string $recipientFirstName,
        string $ticketTitle,
        string $ticketUrl,
    ) {
        parent::__construct();

        $this
            ->to(new Address($recipientEmail))
            ->subject(sprintf('Réponse à votre ticket : %s', $ticketTitle))
            ->htmlTemplate('emails/support/reply_notification.html.twig')
            ->context([
                'recipient_first_name' => $recipientFirstName,
                'ticket_title' => $ticketTitle,
                'ticket_url' => $ticketUrl,
            ]);
    }
}
