<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Client;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * Avis de mise à disposition du rapport d'adéquation dans l'espace client. Volontairement
 * sans aucun contenu du rapport (ni produit, ni montant) : seulement le lien sécurisé.
 */
class AdequacyReportAvailableEmail extends TemplatedEmail
{
    public function __construct(
        string $email,
        string $workspaceName,
        ?string $workspaceContactEmail,
        string $portalUrl,
        int $version,
    ) {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject(sprintf('Votre rapport d\'adéquation est disponible — %s', $workspaceName))
            ->htmlTemplate('emails/client/adequacy_report_available.html.twig')
            ->context([
                'workspace_name' => $workspaceName,
                'workspace_contact_email' => $workspaceContactEmail,
                'portal_url' => $portalUrl,
                'version' => $version,
            ]);
    }
}
