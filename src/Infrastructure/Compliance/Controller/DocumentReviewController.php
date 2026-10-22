<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Controller;

use App\Application\Compliance\UseCase\ComplianceDocument\Review\DocumentReviewUseCase;
use App\Application\Compliance\UseCase\ComplianceDocument\Review\RequestDocumentCorrectionsUseCase;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Infrastructure\Compliance\Voter\ComplianceFolderVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Décision du conseiller sur les pièces de la fiche dossier : valider, refuser (motif), annuler, puis demander les
 * corrections au client. Réservé aux membres habilités à « valider des actes » (même droit qu'approuver le dossier).
 */
#[AsController]
#[Route(path: '/app/compliance/show/{slugId}')]
#[IsGranted(ComplianceFolderVoter::APPROVE, subject: 'complianceFolder', message: 'Vous n\'êtes pas habilité à statuer sur les pièces de ce dossier.')]
final class DocumentReviewController extends AbstractController
{
    public function __construct(
        private readonly DocumentReviewUseCase $review,
        private readonly RequestDocumentCorrectionsUseCase $requestCorrections,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/documents/{documentId}/validate', name: 'app_compliance_document_validate', methods: ['POST'])]
    public function validate(#[MapEntity(mapping: ['slugId' => 'slugId'])] ComplianceFolder $complianceFolder, string $documentId, Request $request): RedirectResponse
    {
        return $this->run($complianceFolder, $request, 'review_doc_' . $documentId, function () use ($documentId, $complianceFolder): string {
            $this->review->validate($documentId, $complianceFolder);

            return 'Pièce validée.';
        });
    }

    #[Route(path: '/documents/{documentId}/reject', name: 'app_compliance_document_reject', methods: ['POST'])]
    public function reject(#[MapEntity(mapping: ['slugId' => 'slugId'])] ComplianceFolder $complianceFolder, string $documentId, Request $request): RedirectResponse
    {
        return $this->run($complianceFolder, $request, 'review_doc_' . $documentId, function () use ($documentId, $complianceFolder, $request): string {
            $this->review->reject(
                $documentId,
                $complianceFolder,
                (string) $request->request->get('reason', ''),
                (string) $request->request->get('comment', ''),
            );

            return 'Pièce refusée. Pensez à demander les corrections au client : il ne le voit pas encore.';
        });
    }

    #[Route(path: '/documents/{documentId}/cancel-review', name: 'app_compliance_document_cancel_review', methods: ['POST'])]
    public function cancel(#[MapEntity(mapping: ['slugId' => 'slugId'])] ComplianceFolder $complianceFolder, string $documentId, Request $request): RedirectResponse
    {
        return $this->run($complianceFolder, $request, 'review_doc_' . $documentId, function () use ($documentId, $complianceFolder, $request): string {
            $this->review->cancel($documentId, $complianceFolder, (string) $request->request->get('reason', ''));

            return 'Décision annulée : la pièce est de nouveau à vérifier.';
        });
    }

    #[Route(path: '/corrections', name: 'app_compliance_request_corrections', methods: ['POST'])]
    public function requestCorrections(#[MapEntity(mapping: ['slugId' => 'slugId'])] ComplianceFolder $complianceFolder, Request $request): RedirectResponse
    {
        return $this->run($complianceFolder, $request, 'request_corrections_' . $complianceFolder->slugId, function () use ($complianceFolder): string {
            $count = ($this->requestCorrections)($complianceFolder);

            return sprintf('Demande envoyée au client : %d pièce%s à fournir à nouveau.', $count, $count > 1 ? 's' : '');
        });
    }

    /**
     * @param callable(): string $action renvoie le message de succès
     */
    private function run(ComplianceFolder $folder, Request $request, string $csrfId, callable $action): RedirectResponse
    {
        $back = $this->redirectToRoute('app_compliance_show', ['slugId' => $folder->slugId]);

        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide ou expiré.');

            return $back;
        }

        try {
            $this->addFlash('success', $action());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Décision sur une pièce impossible.', ['folder' => $folder->slugId, 'reason' => $exception->getMessage()]);
            $this->addFlash('error', 'Une erreur technique est survenue. Réessayez dans un instant.');
        }

        return $back;
    }
}
