<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Compliance;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class DerSignedConfirmationClientEmail extends TemplatedEmail
{
    /**
     * @param list<string> $documentLabels pièces que le client doit encore déposer
     */
    public function __construct(string $email, string $clientName, string $portalUrl, string $workspace_name, array $documentLabels = [])
    {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject('Confirmation de signature de votre DER')
            ->htmlTemplate('emails/compliance/der_signed_confirmation.html.twig')
            ->context([
                'client_name' => $clientName,
                'kysure_portal_url' => $portalUrl,
                'document_labels' => $documentLabels,
                'workspace_name' => $workspace_name,
            ]);
    }
}
