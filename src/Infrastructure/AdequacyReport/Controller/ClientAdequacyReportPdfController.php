<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Controller;

use App\Application\AdequacyReport\UseCase\OpenAdequacyReportForClientUseCase;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\User\Entity\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Remise du rapport d'adéquation au client : redirige vers une URL de stockage signée et
 * temporaire, après contrôle de propriété et trace d'ouverture (preuve de remise).
 */
#[AsController]
#[IsGranted('ROLE_CLIENT', message: 'Espace strictement réservé aux clients.')]
#[Route(path: '/portal/adequacy-report/{slugId}/pdf', name: 'portal_adequacy_report_pdf', requirements: ['slugId' => 'adr_[A-Za-z0-9]+'], methods: ['GET'])]
final class ClientAdequacyReportPdfController extends AbstractController
{
    public function __construct(
        private readonly OpenAdequacyReportForClientUseCase $openReport,
        private readonly DocumentStorageInterface $storage,
    ) {
    }

    public function __invoke(string $slugId): RedirectResponse
    {
        /** @var Client $client */
        $client = $this->getUser();
        $storagePath = ($this->openReport)($slugId, $client);

        if (null === $storagePath) {
            throw new NotFoundHttpException('Rapport d\'adéquation introuvable ou pas encore disponible.');
        }

        return new RedirectResponse($this->storage->getTemporaryUrl($storagePath));
    }
}
