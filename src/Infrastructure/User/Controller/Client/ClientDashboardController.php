<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Controller\Client;

use App\Application\Portal\UseCase\GetClientDashboardUseCase;
use App\Application\Portal\UseCase\GetClientFoldersUseCase;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients finaux.')]
final class ClientDashboardController extends AbstractController
{
    public function __construct(
        private readonly GetClientDashboardUseCase $getClientDashboardUseCase,
        private readonly GetClientFoldersUseCase $getClientFoldersUseCase,
    ) {
    }

    #[Route(path: '/portal/account', name: 'app_portal_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var Client $client */
        $client = $this->getUser();

        // Le contrôleur ne fait plus qu'orchestrer
        $dashboardDto = ($this->getClientDashboardUseCase)($client);

        // Un seul cabinet : son nom sert de branding d'en-tête, comme avant. Plusieurs
        // cabinets à la fois : aucun nom de cabinet n'a de sens seul en en-tête, retombe sur
        // la marque de la plateforme.
        $companyName = 1 === \count($dashboardDto->cabinetRelationships)
            ? $dashboardDto->cabinetRelationships[0]->activeFolder->workspaceName
            : 'KYSURE';

        return $this->render('@app/client/dashboard.html.twig', [
            'dashboard' => $dashboardDto,
            'company_name' => $companyName,
            'folders' => ($this->getClientFoldersUseCase)($client),
        ]);
    }
}
