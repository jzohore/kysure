<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceDocument\RerunDocumentOcrUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bouton « Relancer l'analyse » de la fiche dossier : réservé à l'équipe KYSURE (ROLE_ADMIN) et à l'environnement
 * de dev, car chaque relance peut être facturée (Textract). L'OCR ne décide jamais : la relance remplace seulement
 * les données lues et les points de vigilance, le CGP tranche toujours.
 */
#[AsController]
#[Route(path: '/app/compliance/show/{slugId}/documents/{documentId}/ocr', name: 'app_compliance_document_ocr_rerun', methods: ['POST'])]
#[IsGranted(ComplianceFolderVoter::VIEW, subject: 'complianceFolder', message: 'Ce dossier est strictement confidentiel.')]
final class RerunDocumentOcrController extends AbstractController
{
    public function __construct(
        private readonly RerunDocumentOcrUseCase $useCase,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['slugId' => 'slugId'])]
        ComplianceFolder $complianceFolder,
        string $documentId,
        Request $request,
    ): RedirectResponse {
        if ('dev' !== $this->environment && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Relance de l\'analyse réservée à l\'équipe KYSURE.');
        }

        $back = $this->redirectToRoute('app_compliance_show', ['slugId' => $complianceFolder->slugId]);

        if (!$this->isCsrfTokenValid('rerun_ocr_' . $documentId, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $back;
        }

        try {
            ($this->useCase)($documentId, $complianceFolder);
            $this->addFlash('success', 'Analyse relancée : les résultats s\'afficheront dans quelques secondes.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('OCR : relance manuelle impossible.', ['document_id' => $documentId, 'reason' => $exception->getMessage()]);
            $this->addFlash('error', 'Impossible de relancer l\'analyse pour le moment.');
        }

        return $back;
    }
}
