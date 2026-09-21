<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Controller;

use App\Application\Suitability\UseCase\FindValidatedInvestorProfileForCabinetUseCase;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Redirige vers une URL de stockage signée et temporaire pour le PDF de synthèse (déclaration
 * d'adéquation) d'un profil investisseur validé, côté cabinet. Le scope au workspace courant
 * (et non un simple contrôle de rôle) est ce qui empêche un CGP d'accéder au profil d'un
 * client suivi par un cabinet concurrent, même en devinant son slug.
 */
#[AsController]
#[IsGranted('ROLE_USER')]
#[Route(path: '/app/suitability/profil-investisseur/{slugId}/pdf', name: 'app_investor_profile_pdf_download', methods: ['GET'])]
readonly class InvestorProfilePdfDownloadController
{
    public function __construct(
        private FindValidatedInvestorProfileForCabinetUseCase $findProfileUseCase,
        private CurrentWorkspaceProvider $workspaceProvider,
        private DocumentStorageInterface $storage,
    ) {
    }

    public function __invoke(string $slugId): RedirectResponse
    {
        $profile = ($this->findProfileUseCase)($slugId, $this->workspaceProvider->getWorkspace());

        if (!$profile instanceof ValidatedInvestorProfile || null === $profile->pdfStoragePath) {
            throw new NotFoundHttpException('PDF de synthèse introuvable ou pas encore généré.');
        }

        return new RedirectResponse($this->storage->getTemporaryUrl($profile->pdfStoragePath));
    }
}
