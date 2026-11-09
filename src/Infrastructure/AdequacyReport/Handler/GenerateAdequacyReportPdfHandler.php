<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Handler;

use App\Application\AdequacyReport\UseCase\AdequacyReportDocumentAssembler;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Port\DocumentStorageInterface;
use App\Infrastructure\AdequacyReport\Message\GenerateAdequacyReportPdfMessage;
use App\Infrastructure\Pdf\PdfGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Génère le PDF d'un rapport d'adéquation validé, sur le modèle de
 * {@see \App\Infrastructure\Suitability\Handler\GenerateInvestorProfilePdfHandler}.
 *
 * Idempotence par empreinte : un rejeu (retry Messenger, redéploiement) ne regénère jamais un
 * PDF déjà produit, et le PDF n'est produit que si le contenu stocké correspond exactement à
 * l'empreinte validée (portée par le message) — sinon il restituerait autre chose que ce que
 * le CGP a signé. Le PDF n'est qu'une restitution lisible : la preuve légale reste l'empreinte
 * SHA-256, l'horodatage et le nom du valideur portés par le rapport.
 */
#[AsMessageHandler]
readonly class GenerateAdequacyReportPdfHandler
{
    public function __construct(
        private AdequacyReportRepositoryInterface $reportRepository,
        private AdequacyReportDocumentAssembler $documentAssembler,
        private PdfGeneratorInterface $pdfGenerator,
        private DocumentStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateAdequacyReportPdfMessage $message): void
    {
        $report = $this->reportRepository->findBySlugId($message->reportSlugId);
        if (!$report instanceof AdequacyReport) {
            throw new UnrecoverableMessageHandlingException('Rapport d\'adéquation introuvable pour la génération PDF.');
        }

        if ($report->isPdfAvailable()) {
            return;
        }

        if (!hash_equals($report->contentHash, $message->contentHash) || !$report->matchesStoredHash()) {
            // Altération du contenu : réessayer n'y changera rien, et générer serait pire.
            $this->logger->critical('Rapport d\'adéquation : empreinte du contenu différente de celle validée, PDF non généré.', [
                'report_slug_id' => $report->slugId,
            ]);

            throw new UnrecoverableMessageHandlingException('Empreinte du rapport d\'adéquation invalide.');
        }

        $tempFilePath = null;

        try {
            $pdfContent = $this->pdfGenerator->generateFromHtml('@app/pdf/adequacy_report_template.html.twig', [
                'report' => $this->documentAssembler->fromReport($report),
                'is_pdf' => true,
            ]);

            if (!str_starts_with($pdfContent, '%PDF-')) {
                throw new \RuntimeException("Gotenberg n'a pas renvoyé un PDF valide. Reçu : " . substr($pdfContent, 0, 100));
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('adequacy_report_', true) . '.pdf';
            file_put_contents($tempFilePath, $pdfContent);

            $fileToStore = new UploadedFile(
                path: $tempFilePath,
                originalName: sprintf('rapport_adequation_v%d_%s.pdf', $report->version, substr($report->contentHash, 0, 12)),
                mimeType: 'application/pdf',
                test: true,
            );

            $directory = sprintf('documents/adequacy-report/%s/%s', $report->workspace->slugId, $report->complianceFolder->slugId);
            $report->markPdfGenerated($this->storage->store($fileToStore, $directory));
            $this->reportRepository->save($report);
        } catch (\Throwable $e) {
            $this->logger->error('Échec de génération du PDF du rapport d\'adéquation.', [
                'report_slug_id' => $message->reportSlugId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Déclenche le retry Messenger
        } finally {
            if (null !== $tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }
}
