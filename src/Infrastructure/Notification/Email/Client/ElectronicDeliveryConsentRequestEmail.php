<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Email\Client;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;

/**
 * Invite le client à accepter la remise électronique de ses documents (avenant au DER), depuis
 * son espace sécurisé. Aucun lien d'acceptation direct : l'accord se donne une fois connecté.
 */
class ElectronicDeliveryConsentRequestEmail extends TemplatedEmail
{
    public function __construct(
        string $email,
        string $workspaceName,
        ?string $workspaceContactEmail,
        string $portalUrl,
    ) {
        parent::__construct();

        $this
            ->to(new Address($email))
            ->subject(sprintf('%s : recevoir vos documents dans votre espace sécurisé', $workspaceName))
            ->htmlTemplate('emails/client/electronic_delivery_consent_request.html.twig')
            ->context([
                'workspace_name' => $workspaceName,
                'workspace_contact_email' => $workspaceContactEmail,
                'portal_url' => $portalUrl,
            ]);
    }
}
