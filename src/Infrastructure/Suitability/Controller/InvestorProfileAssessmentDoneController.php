<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Controller;

use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de clôture du questionnaire. Ne montre volontairement aucun détail du profil calculé
 * (score, niveau 1-7) : c'est une proposition non encore validée par le CGP (lot 2), sur le
 * même principe que le score LCB-FT jamais exposé au client (cahier des charges §3, fin du
 * parcours client).
 */
#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients.')]
final class InvestorProfileAssessmentDoneController extends AbstractController
{
    #[Route(path: '/portal/profil-investisseur/termine', name: 'app_portal_investor_profile_done', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var Client $client */
        $client = $this->getUser();
        $workspace = $client->workspaces->first();

        return $this->render('@app/client/investor_profile_done.html.twig', [
            'company_name' => false !== $workspace ? $workspace->name : 'KYSURE',
        ]);
    }
}
