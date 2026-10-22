<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Compliance;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

class DocumentCorrectionsRequestClientEmail extends TemplatedEmail
{
    /**
     * @param list<array{label: string, reason: string}> $items pièces à fournir à nouveau, avec leur motif
     */
    public function __construct(string $email, string $clientName, string $portalUrl, string $workspaceName, array $items)
    {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject(sprintf('%s : %s à fournir à nouveau', $workspaceName, 1 === count($items) ? 'une pièce' : count($items) . ' pièces'))
            ->htmlTemplate('emails/compliance/document_corrections_request.html.twig')
            ->context([
                'client_name' => $clientName,
                'kysure_portal_url' => $portalUrl,
                'workspace_name' => $workspaceName,
                'items' => $items,
            ]);
    }
}
