<?php

declare(strict_types=1);

namespace App\Application\Portal\UseCase;

use App\Application\Portal\DTO\AdequacyReportSummaryDto;
use App\Application\Portal\DTO\DerSummaryDto;
use App\Application\Portal\DTO\DocumentItemDto;
use App\Application\Portal\DTO\FolderDetailDto;
use App\Domain\AdequacyReport\Entity\AdequacyReport;
use App\Domain\AdequacyReport\Repository\AdequacyReportRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Exception\ComplianceFolderNotFoundException;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\User\Entity\Client;
use App\Domain\User\Enum\ClientPortalStatus;

readonly class GetFolderDetailUseCase
{
    public function __construct(
        private ComplianceFolderRepositoryInterface $folderRepository,
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private AdequacyReportRepositoryInterface $adequacyReportRepository,
    ) {
    }

    public function __invoke(Client $client, string $folderId): FolderDetailDto
    {
        // 1. Récupération sécurisée : On s'assure que le dossier appartient bien à CE client.
        $folder = $this->folderRepository->findOneBySlugIdAndClient($folderId, $client);

        if (!$folder instanceof \App\Domain\Compliance\Entity\ComplianceFolder) {
            throw ComplianceFolderNotFoundException::withId($folderId);
        }

        // 2. Récupération des documents liés
        $documents = $this->documentRepository->findByFolder($folder);

        // 3. Mapping des documents vers le sous-DTO
        $documentDtos = array_map(static fn (ComplianceDocument $doc): DocumentItemDto => new DocumentItemDto(
            id: $doc->slugId,
            name: $doc->type->getLabel(), // Suppose un Enum DocumentType
            // Un refus encore interne au cabinet (pas signalé) n'est pas montré au client.
            status: DocumentStatus::REJECTED === $doc->status && !$doc->isRejectionVisibleToClient() ? DocumentStatus::UPLOADED : $doc->status,
            uploadedAtFormatted: $doc->uploadedAt?->format('d/m/Y à H:i'),
            rejectionReason: $doc->isRejectionVisibleToClient() ? $doc->rejectionReason : null,
            storagePath: $doc->storagePath,
        ), $documents);

        // 4. Récapitulatif du DER : ce que le client a déjà fait (accuser réception)
        //    et ce qu'il peut récupérer (le DER, l'attestation).
        $der = null;
        $derDocument = $this->documentRepository->findDerByFolder($folder);
        if ($derDocument instanceof ComplianceDocument && null !== $derDocument->storagePath) {
            $acknowledgement = $derDocument->acknowledgementInForce();
            if ($acknowledgement instanceof DerAcknowledgement) {
                $der = new DerSummaryDto(
                    acknowledgedAtFormatted: $acknowledgement->acknowledgedAt->format('d/m/Y à H:i'),
                    pdfSha256: $acknowledgement->pdfSha256,
                    pdfStoragePath: $derDocument->storagePath,
                    certificateStoragePath: $acknowledgement->certificateStoragePath,
                );
            }
        }

        // 5. Rapports d'adéquation remis (lot 5) : seulement ceux dont le PDF est prêt, et
        //    seulement ceux destinés à CE client (le dossier lui appartient déjà, garde redondante).
        $adequacyReports = array_values(array_map(
            static fn (AdequacyReport $report): AdequacyReportSummaryDto => new AdequacyReportSummaryDto(
                slugId: $report->slugId,
                version: $report->version,
                validatedAt: $report->validatedAt,
                contentHash: $report->contentHash,
                inForce: $report->isInForce(),
            ),
            array_filter(
                $this->adequacyReportRepository->findByFolder($folder),
                static fn (AdequacyReport $report): bool => $report->isPdfAvailable() && $report->client === $client,
            ),
        ));

        // 6. Assemblage final
        return new FolderDetailDto(
            id: $folder->slugId,
            title: $folder->title ?? 'Dossier de conformité',
            reference: $folder->reference,
            openedAtFormatted: $folder->createdAt->format('d/m/Y'),
            status: ClientPortalStatus::resolve($folder->status, $this->documentRepository->countPendingForFolder($folder)),
            documents: $documentDtos,
            der: $der,
            adequacyReports: $adequacyReports,
        );
    }
}
