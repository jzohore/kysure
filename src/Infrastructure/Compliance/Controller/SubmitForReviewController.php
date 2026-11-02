<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceFolder\SubmitForReviewUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route(path: '/app/compliance/{slugId}/submit-for-review', name: 'app_compliance_submit_for_review', methods: ['POST'])]
class SubmitForReviewController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SubmitForReviewUseCase $submitForReviewUseCase,
        private readonly Security $security,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $complianceFolder,
    ): RedirectResponse {
        try {
            // 1. Exécution du UseCase
            $reference = $complianceFolder->reference;
            ($this->submitForReviewUseCase)($complianceFolder);

            // 2. Feedback positif pour l'UX
            $this->addFlash('success', sprintf('Le dossier %s est en attente de révision.', $reference));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            $this->logger->warning('Tentative invalide de soumission d\'un dossier', [
                'folder_id' => $complianceFolder->id,
                'reason' => $exception->getMessage(),
            ]);
        } catch (\Exception $exception) {
            $this->addFlash('error', 'Une erreur technique est survenue lors de la soumission du dossier.');
            $this->logger->error('Erreur système lors de la soumission du dossier', [
                'folder_id' => $complianceFolder->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        if ($this->security->isGranted('ROLE_CLIENT')) {
            return $this->redirectToRoute('portal_folders_show', ['slugId' => $complianceFolder->slugId]);
        }

        return $this->redirectToRoute('portal_folders_show', ['slugId' => $complianceFolder->slugId]);
    }
}
