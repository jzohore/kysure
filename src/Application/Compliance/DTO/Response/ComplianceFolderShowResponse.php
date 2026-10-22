<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Response;

/**
 * ViewDTO dédié exclusivement à l'affichage de la page Show du KYC.
 * Totalement décorrélé de la complexité de l'Entité Doctrine.
 */
readonly class ComplianceFolderShowResponse
{
    /**
     * @param array<string, mixed>|null $postMeetingReport
     */
    public function __construct(
        public string $id,
        public string $slugId,
        public string $workspaceName,
        public string $workspaceEmail,
        public string $reference,
        public string $statusValue,
        public string $statusLabel,
        public bool $isManual,
        public bool $isKyb,
        public bool $isDraft,
        public bool $isArchived,
        public bool $isAcceptedRecording,
        public string $method,

        // 💡 Les données calculées et prêtes à l'emploi pour Twig
        public string $headerTitle,    // Ex: "Nom de l'entreprise" ou "Jean Dupont"
        public string $headerSubtitle, // Ex: "SIRET : 123456789" ou "Profil Individuel (KYC)"

        // 💡 Contact
        public string $contactName,
        public int $workspaceRemainingMinutes,
        // 💡 Collections de sous-DTOs pré-triées
        /** @var ComplianceDocumentResponse[] */
        public array $companyDocuments,
        /** @var ComplianceDocumentResponse[] */
        public array $individualDocuments,
        /** @var StakeholderResponse[] */
        public array $stakeholders,
        /** @var array<int|string, array{title: mixed, description: mixed, date: non-falsy-string}> */
        public array $history,
        public ?string $contactFirstName,
        public ?string $contactLastName,
        public ?string $contactEmail,
        public ?string $type,
        public ?string $contactAddress = null,
        public ?array $postMeetingReport = null,
        // Pièces refusées que le client ne connaît pas encore : à lui signaler par « Demander les corrections ».
        public int $pendingCorrectionCount = 0,
    ) {
    }
}
