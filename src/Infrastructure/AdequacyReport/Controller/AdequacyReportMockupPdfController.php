<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Controller;

use App\Application\AdequacyReport\UseCase\BuildAdequacyReportMockupUseCase;
use App\Infrastructure\Pdf\PdfGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Webmozart\Assert\Assert;

/**
 * Téléchargement PDF de la maquette (lot 0). Génération synchrone (pas de Messenger, pas de
 * stockage) : cette route n'a pas vocation à survivre au-delà de la validation du cabinet
 * pilote — le vrai rapport d'adéquation (lot 5) suivra le pattern async +
 * stockage de {@see \App\Infrastructure\Suitability\Handler\GenerateInvestorProfilePdfHandler}.
 */
#[AsController]
#[Route(path: '/app/adequacy-report/mockup/pdf', name: 'app_adequacy_report_mockup_pdf', methods: ['GET'])]
final class AdequacyReportMockupPdfController extends AbstractController
{
    public function __construct(
        private readonly BuildAdequacyReportMockupUseCase $buildMockup,
        private readonly PdfGeneratorInterface $pdfGenerator,
    ) {
    }

    public function __invoke(): Response
    {
        $pdfContent = $this->pdfGenerator->generateFromHtml('@app/pdf/adequacy_report_mockup_template.html.twig', [
            'report' => $this->buildMockup->build(),
            'is_pdf' => true,
        ]);

        Assert::startsWith($pdfContent, '%PDF-', "Gotenberg n'a pas renvoyé un PDF valide.");

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="maquette-rapport-adequation.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
