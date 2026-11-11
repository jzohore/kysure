<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Compliance;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * Avertit le cabinet qu'un client a soumis son dossier : il attend la décision de conformité.
 */
class WorkspaceFolderSubmittedEmail extends TemplatedEmail
{
    public function __construct(string $email, string $clientName, string $folderReference, string $reviewUrl)
    {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject(sprintf('Dossier %s soumis par %s', $folderReference, $clientName))
            ->htmlTemplate('emails/compliance/workspace_folder_submitted.html.twig')
            ->context([
                'client_name' => $clientName,
                'folder_reference' => $folderReference,
                'review_url' => $reviewUrl,
            ]);
    }
}
