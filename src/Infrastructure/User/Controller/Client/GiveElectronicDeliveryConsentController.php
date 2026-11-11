<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Client;

use App\Application\Compliance\UseCase\ElectronicDelivery\GiveElectronicDeliveryConsentUseCase;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le client accepte la remise électronique de ses documents (avenant au DER), depuis la page
 * de son dossier. La propriété du dossier est vérifiée par le use case.
 */
#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients.')]
#[Route(path: '/portal/folders/{id}/accord-remise-electronique', name: 'portal_electronic_delivery_consent', methods: ['POST'])]
final class GiveElectronicDeliveryConsentController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'electronic_delivery_consent';

    public function __construct(
        private readonly GiveElectronicDeliveryConsentUseCase $giveConsent,
    ) {
    }

    public function __invoke(string $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton de sécurité invalide.');
        }

        if (!$request->request->getBoolean('accepted')) {
            $this->addFlash('error', 'Cochez la case pour donner votre accord.');

            return $this->redirectToRoute('portal_folders_show', ['id' => $id]);
        }

        /** @var Client $client */
        $client = $this->getUser();

        try {
            ($this->giveConsent)($client, $id);
            $this->addFlash('success', 'Merci, votre accord est enregistré : vos documents vous seront remis dans cet espace.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('portal_folders_show', ['id' => $id]);
    }
}
