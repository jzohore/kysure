<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Controller;

use App\Application\Suitability\UseCase\FindValidatedInvestorProfileForClientUseCase;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Redirige vers une URL de stockage signée et temporaire pour le PDF de synthèse (déclaration
 * d'adéquation) d'un profil investisseur validé, côté client. La propriété du profil (et non
 * un simple contrôle de rôle) est ce qui empêche un client de télécharger le profil d'un autre.
 */
#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients.')]
#[Route(path: '/portal/profil-investisseur/pdf/{slugId}', name: 'app_portal_investor_profile_pdf_download', methods: ['GET'])]
final class ClientInvestorProfilePdfDownloadController extends AbstractController
{
    public function __construct(
        private readonly FindValidatedInvestorProfileForClientUseCase $findProfileUseCase,
        private readonly DocumentStorageInterface $storage,
    ) {
    }

    public function __invoke(string $slugId): RedirectResponse
    {
        /** @var Client $client */
        $client = $this->getUser();
        $profile = ($this->findProfileUseCase)($slugId, $client);

        if (!$profile instanceof ValidatedInvestorProfile || null === $profile->pdfStoragePath) {
            throw new NotFoundHttpException('PDF de synthèse introuvable ou pas encore généré.');
        }

        return new RedirectResponse($this->storage->getTemporaryUrl($profile->pdfStoragePath));
    }
}
