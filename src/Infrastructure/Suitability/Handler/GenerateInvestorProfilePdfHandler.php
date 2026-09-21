<?php

declare(strict_types=1);

namespace App\Infrastructure\Suitability\Handler;

use App\Application\Suitability\UseCase\InvestorProfileSynthesisAssembler;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Infrastructure\Pdf\PdfGeneratorInterface;
use App\Infrastructure\Suitability\Message\GenerateInvestorProfilePdfMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Webmozart\Assert\Assert;

/**
 * Génère le PDF de synthèse (déclaration d'adéquation) d'un profil investisseur validé.
 * Aucune signature/accusé de réception associé (v0) : la preuve légale, c'est déjà le hash
 * SHA-256 + l'horodatage + le nom du valideur portés par {@see ValidatedInvestorProfile} —
 * le PDF n'est qu'une restitution lisible de cet instantané, jamais régénéré ensuite (le
 * profil validé est immuable).
 */
#[AsMessageHandler]
readonly class GenerateInvestorProfilePdfHandler
{
    public function __construct(
        private ValidatedInvestorProfileRepositoryInterface $profileRepository,
        private InvestorProfileSynthesisAssembler $synthesisAssembler,
        private PdfGeneratorInterface $pdfGenerator,
        private DocumentStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateInvestorProfilePdfMessage $message): void
    {
        $profile = $this->profileRepository->findBySlugId($message->profileSlugId);
        Assert::notNull($profile, 'Profil investisseur validé introuvable pour la génération PDF.');

        if (null !== $profile->pdfStoragePath) {
            // Idempotence : un replay du message (retry Messenger, redéploiement) ne doit pas
            // regénérer un fichier déjà produit.
            return;
        }

        $tempFilePath = null;

        try {
            $synthesis = $this->synthesisAssembler->assemble($profile);
            $pdfContent = $this->pdfGenerator->generateFromHtml('@app/pdf/investor_profile_synthesis_template.html.twig', [
                'synthesis' => $synthesis,
            ]);

            if ('' === $pdfContent || '0' === $pdfContent) {
                throw new \RuntimeException('Le générateur PDF a renvoyé un contenu vide.');
            }

            if (!str_starts_with($pdfContent, '%PDF-')) {
                throw new \RuntimeException("Gotenberg n'a pas renvoyé un PDF valide. Reçu : " . substr($pdfContent, 0, 100));
            }

            $tempFilePath = sys_get_temp_dir() . '/' . uniqid('investor_profile_', true) . '.pdf';
            file_put_contents($tempFilePath, $pdfContent);

            $fileToStore = new UploadedFile(
                path: $tempFilePath,
                originalName: sprintf('profil_investisseur_v%d_%s.pdf', $profile->version, $profile->client->slugId),
                mimeType: 'application/pdf',
                test: true
            );

            $directory = sprintf('documents/investor-profile/%s/%s', $profile->workspace->slugId, $profile->client->slugId);
            $storagePath = $this->storage->store($fileToStore, $directory);

            $profile->markPdfGenerated($storagePath);
            $this->profileRepository->save($profile);
        } catch (\Throwable $e) {
            $this->logger->error('Échec de génération du PDF de synthèse du profil investisseur.', [
                'profile_slug_id' => $message->profileSlugId,
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
