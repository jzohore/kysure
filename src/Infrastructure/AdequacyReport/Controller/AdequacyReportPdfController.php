<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Controller;

use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\AdequacyReport\Voter\AdequacyReportVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PDF d'un rapport d'adéquation validé, côté cabinet : URL de stockage signée et temporaire,
 * après contrôle du voter (membre du cabinet + dossier visible).
 */
#[AsController]
#[IsGranted('ROLE_USER')]
#[Route(path: '/app/adequacy-report/{slugId}/pdf', name: 'app_adequacy_report_pdf', requirements: ['slugId' => 'adr_[A-Za-z0-9]+'], methods: ['GET'])]
final class AdequacyReportPdfController extends AbstractController
{
    public function __construct(
        private readonly DocumentStorageInterface $storage,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        AdequacyReport $report,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted(AdequacyReportVoter::VIEW, $report);

        if (null === $report->pdfStoragePath) {
            throw new NotFoundHttpException('Le PDF de ce rapport est en cours de génération.');
        }

        return new RedirectResponse($this->storage->getTemporaryUrl($report->pdfStoragePath));
    }
}
