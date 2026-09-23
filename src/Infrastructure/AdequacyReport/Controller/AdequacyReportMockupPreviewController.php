<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Controller;

use App\Application\AdequacyReport\UseCase\BuildAdequacyReportMockupUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aperçu HTML de la maquette du rapport d'adéquation (lot 0, voir mémoire
 * cif-pilot-roadmap-phase2). Réservé aux conseillers du cabinet connecté (garde `^/app/`,
 * ROLE_USER) — pas de garde-fou supplémentaire nécessaire, aucune donnée client réelle n'est
 * affichée ici.
 */
#[AsController]
#[Route(path: '/app/adequacy-report/mockup', name: 'app_adequacy_report_mockup_preview', methods: ['GET'])]
final class AdequacyReportMockupPreviewController extends AbstractController
{
    public function __construct(
        private readonly BuildAdequacyReportMockupUseCase $buildMockup,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@app/pdf/adequacy_report_mockup_template.html.twig', [
            'report' => $this->buildMockup->build(),
            'is_pdf' => false,
        ]);
    }
}
