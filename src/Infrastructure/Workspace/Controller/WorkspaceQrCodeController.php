<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace\Controller;

use App\Domain\Firm\Entity\RegulatoryProfile;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Endroid\QrCode\Builder\BuilderInterface;
use Endroid\QrCode\Color\Color;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WorkspaceQrCodeController extends AbstractController
{
    public function __construct(
        private readonly BuilderInterface $customQrCodeBuilder,
        private readonly CurrentWorkspaceProvider $currentWorkspaceProvider,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/app/onboarding/qr', name: 'app_onboarding_qr', methods: ['GET'])]
    public function displayPage(): Response
    {
        $profile = $this->currentWorkspaceProvider->getWorkspace()->regulatoryProfile;

        if (!$profile instanceof RegulatoryProfile) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('@app/workspace/qr_display.html.twig', [
            'page_title' => 'Entrée en relation Express',
            'is_profil_valid' => $profile->isProfileValid(),
        ]);
    }

    #[Route('/app/onboarding/qr/image', name: 'app_onboarding_qr_image', methods: ['GET'])]
    public function generateImage(): Response
    {
        $workspace = $this->currentWorkspaceProvider->getWorkspace();
        $onboardingUrl = $this->router->generate('app_client_onboarding', [
            'publicToken' => $workspace->publicToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        // Utilisation de la méthode statique moderne d'Endroid v5+
        $result = $this->customQrCodeBuilder->build(
            data: $onboardingUrl,
            size: 400,
            margin: 20,
            foregroundColor: new Color(15, 23, 42), // Slate 900
            backgroundColor: new Color(255, 255, 255) // Blanc
        );

        return new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
        ]);
    }
}
