<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Response;

readonly class ComplianceDocumentResponse
{
    /**
     * @param array<string, mixed>|null $ocrData
     * @param list<string>|null         $ocrFindings points de vigilance de l'analyse auto, à trancher par le CGP
     */
    public function __construct(
        public string $id,
        public string $typeLabel,
        public string $statusValue,
        public bool $isAskToClient,
        public ?string $storagePath,
        public ?string $rejectionReason,
        public ?array $ocrData, // Les données de la macro Twig
        public ?array $ocrFindings,
        public ?string $stakeholderSlug,
        public ?string $filename,
        public ?string $mimeType,
        public ?int $size,
        // Demandée au client ET pas encore déposée : c'est ce qui justifie « En attente du client ».
        public bool $isAwaitingClient = false,
        // État de l'analyse automatique ({@see \App\Domain\Compliance\Enum\OcrAnalysisState}) : informatif, ne décide de rien.
        public string $ocrState = 'not_applicable',
        // Date de la dernière analyse automatique aboutie du fichier courant (null : jamais analysé, ou en cours).
        public ?\DateTimeImmutable $ocrAnalyzedAt = null,
        // Décision du conseiller sur le fichier courant.
        public bool $awaitsReview = false,
        public bool $canCancelReview = false,
        public ?\DateTimeImmutable $reviewedAt = null,
        public ?string $reviewedByName = null,
        // Refus signalé au client (null : refus encore interne au cabinet).
        public ?\DateTimeImmutable $correctionRequestedAt = null,
    ) {
    }
}
