<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Suitability;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class WorkspaceInvestorProfileSubmittedEmail extends TemplatedEmail
{
    public function __construct(string $email, string $clientName, string $reviewUrl)
    {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject('Questionnaire profil investisseur reçu - ' . $clientName)
            ->htmlTemplate('emails/suitability/workspace_assessment_submitted.html.twig')
            ->context([
                'client_name' => $clientName,
                'review_url' => $reviewUrl,
            ]);
    }
}
