<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Suitability;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class ClientAssessmentReminderEmail extends TemplatedEmail
{
    public function __construct(string $email, string $firstName, string $actionUrl)
    {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject('Il vous reste quelques questions à répondre')
            ->htmlTemplate('emails/suitability/client_assessment_reminder.html.twig')
            ->context([
                'first_name' => $firstName,
                'action_url' => $actionUrl,
            ]);
    }
}
