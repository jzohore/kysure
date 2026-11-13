<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Client;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * Le dossier de conformité du client est validé par son cabinet.
 */
class FolderApprovedEmail extends TemplatedEmail
{
    public function __construct(
        string $email,
        string $workspaceName,
        ?string $workspaceContactEmail,
        string $folderReference,
        string $portalUrl,
    ) {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject(sprintf('Votre dossier %s est validé — %s', $folderReference, $workspaceName))
            ->htmlTemplate('emails/client/folder_approved.html.twig')
            ->context([
                'workspace_name' => $workspaceName,
                'workspace_contact_email' => $workspaceContactEmail,
                'folder_reference' => $folderReference,
                'portal_url' => $portalUrl,
            ]);
    }
}
