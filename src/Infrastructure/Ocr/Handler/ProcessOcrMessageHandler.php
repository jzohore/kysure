<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr\Handler;

use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Event\DocumentOcrProcessedEvent;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Kyc\Validator\DocumentValidator;
use App\Domain\Port\DocumentStorageInterface;
use App\Domain\Port\OcrProviderInterface;
use App\Infrastructure\Ocr\Message\ProcessOcrMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Webmozart\Assert\Assert;

/**
 * Analyse automatique d'un document déposé par le client : extraction OCR
 * (Textract) puis contrôles métier ({@see DocumentValidator}).
 *
 * ⚠️ Cette analyse ne **décide** de rien : elle n'accepte ni ne rejette jamais
 * un document. Elle stocke les données extraites et une liste de points de
 * vigilance ; le document reste en attente de vérification par le CGP, qui seul
 * tranche (et dont la décision, elle, portera un acteur et un motif tracés).
 */
#[AsMessageHandler]
final readonly class ProcessOcrMessageHandler
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $repository,
        private OcrProviderInterface $ocrProvider,
        private DocumentStorageInterface $storage,
        private DocumentValidator $validator,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessOcrMessage $message): void
    {
        $doc = $this->repository->findById($message->documentId);
        Assert::notNull($doc, sprintf('Document introuvable : %s', $message->documentId));

        // 🛡️ Idempotence : ne pas réanalyser (rappel Textract facturé) et surtout ne jamais
        // écraser une analyse déjà faite ni une décision humaine (statut VALID / REJECTED).
        // Un nouveau fichier a, lui, remis l'analyse à zéro (ComplianceDocument::markAsUploaded).
        if (!$doc->needsOcrAnalysis()) {
            return;
        }

        Assert::notNull($doc->storagePath, 'Le chemin du document est requis.');
        $fullPath = $this->storage->getTemporaryUrl($doc->storagePath);
        Assert::notNull($fullPath, 'Impossible de générer une URL temporaire pour le document.');

        try {
            $extractedData = $this->ocrProvider->extractData($doc->type, $fullPath);
        } catch (\DomainException $exception) {
            // Contrat de OcrProviderInterface : \DomainException = document illisible
            // ou non pris en charge. Condition définitive → pas de retry, on
            // signale et on laisse le CGP demander une nouvelle version.
            $this->logger->info('OCR : document non exploitable, signalé pour vérification humaine.', [
                'document_id' => $message->documentId,
                'reason' => $exception->getMessage(),
            ]);

            $this->finish($doc, null, ['Le document n\'a pas pu être analysé automatiquement (illisible ou format non pris en charge). Vérification manuelle requise.'], extractionSucceeded: false);

            return;
        }

        $hasData = [] !== array_filter($extractedData, static fn ($value): bool => !empty($value));

        if (!$hasData) {
            $this->finish($doc, null, ['Aucune donnée n\'a pu être extraite du document. Vérification manuelle requise.'], extractionSucceeded: false);

            return;
        }

        $doc->setExtractedData($extractedData);
        $findings = $this->validator->validate($doc);

        $this->finish($doc, $extractedData, $findings, extractionSucceeded: true);
    }

    /**
     * @param array<string, mixed>|null $extractedData
     * @param list<string>              $findings
     */
    private function finish(ComplianceDocument $doc, ?array $extractedData, array $findings, bool $extractionSucceeded): void
    {
        $doc->attachOcrAnalysis($extractedData, $findings, DocumentValidator::VERSION);
        $this->repository->save($doc);

        $this->eventDispatcher->dispatch(new DocumentOcrProcessedEvent(
            document: $doc,
            findings: $findings,
            extractionSucceeded: $extractionSucceeded,
        ));
    }
}
