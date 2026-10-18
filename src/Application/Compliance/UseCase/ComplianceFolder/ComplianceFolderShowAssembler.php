<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\ComplianceFolder;

use App\Application\Compliance\DTO\Response\ComplianceDocumentResponse;
use App\Application\Compliance\DTO\Response\ComplianceFolderShowResponse;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\DerAcknowledgement;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Kyc\Enum\DocumentStatus;

class ComplianceFolderShowAssembler
{
    public function assemble(ComplianceFolder $folder): ComplianceFolderShowResponse
    {
        $isDraft = in_array($folder->status, [
            ComplianceFolderStatus::DRAFT,
            ComplianceFolderStatus::DER_GENERATED,
        ], true);

        $isArchived = $folder->isArchived();
        $isKyb = $folder instanceof BusinessFolder;

        // 1. Formatage du Titre et Sous-titre (La logique quitte Twig !)
        $headerTitle = 'Dossier incomplet';
        $headerSubtitle = 'Non renseigné';

        if ($folder instanceof BusinessFolder) {
            $headerTitle = $folder->companyName ?: 'En attente de l\'entreprise';
            $headerSubtitle = $folder->siret ? "SIRET : $folder->siret" : 'SIRET non renseigné';
        } elseif ($folder instanceof IndividualFolder) {
            $headerTitle = trim("$folder->firstName $folder->lastName");
            $headerSubtitle = 'Profil Individuel (KYC)';
        }

        // 2. Formatage du contact (Fallback propre)
        $contactName = $folder instanceof IndividualFolder
            ? trim("$folder->firstName $folder->lastName")
            : 'Non défini';

        $contactFirstName = $folder instanceof IndividualFolder
            ? $folder->firstName
            : 'Non défini';

        $contactLastName = $folder instanceof IndividualFolder
            ? $folder->lastName
            : 'Non défini';

        $contactEmail = $folder instanceof IndividualFolder
            ? $folder->email
            : 'Non défini';

        $contactAddress = $folder instanceof IndividualFolder ? $folder->address : null;

        $type = $folder instanceof IndividualFolder ? 'individual' : 'company';
        // Note: Assure-toi d'avoir une méthode de fallback pour l'email si elle n'est pas sur la classe parente

        // 3. Tri des documents directement ici
        $companyDocs = [];
        $individualDocs = [];

        foreach ($folder->documents as $doc) {
            $docDto = new ComplianceDocumentResponse(
                id: (string) $doc->id,
                typeLabel: $doc->type->getLabel(), // Ou $doc->type->label() selon ton Enum
                // Le DER n'a pas de cycle « à vérifier / validé » : sa preuve est l'accusé de réception.
                statusValue: DocumentType::DER === $doc->type && $doc->acknowledgementInForce() instanceof DerAcknowledgement
                    ? 'acknowledged'
                    : $doc->status->value,
                isAskToClient: $doc->isAskToClient, // À adapter selon ton Entité
                storagePath: $doc->storagePath,
                rejectionReason: $doc->rejectionReason ?? null,
                ocrData: $doc->ocrData, // Si c'est un tableau
                ocrFindings: $doc->ocrFindings,
                stakeholderSlug: $doc->stakeholderSlug ?? null,
                filename: $doc->filename,
                mimeType: $doc->mimeType,
                size: $doc->size,
                isAwaitingClient: $doc->isAskToClient && !$doc->isReceived() && DocumentStatus::PROCESSING !== $doc->status,
                ocrState: $doc->ocrAnalysisState()->value,
            );

            if ($isKyb && null === $docDto->stakeholderSlug) {
                $companyDocs[] = $docDto;
            } else {
                $individualDocs[] = $docDto;
            }
        }

        // 4. Formatage de l'historique
        $historyFormatted = array_map(static function (array $h): array {
            $saveAt = $h['saveAt'] ?? null;
            $dateStr = 'N/A'; // Fallback par défaut

            // 1. Le cas attendu : c'est un objet DatePoint ou DateTimeImmutable
            if ($saveAt instanceof \DateTimeInterface) {
                $dateStr = $saveAt->format('d/m/y H:i');
            }
            // 2. Le fallback "Legacy" : c'est un tableau (ex: vieille donnée JSON non castée)
            elseif (is_array($saveAt) && isset($saveAt['date'])) {
                try {
                    $dateStr = new \DateTimeImmutable($saveAt['date'])->format('d/m/y H:i');
                } catch (\Exception) {
                    // Sécurité anti-crash si la date est malformée
                }
            }
            // 3. Fallback si c'est une string brute
            elseif (is_string($saveAt)) {
                try {
                    $dateStr = new \DateTimeImmutable($saveAt)->format('d/m/y H:i');
                } catch (\Exception) {
                }
            }

            return [
                'title' => $h['title'] ?? 'Action',
                'description' => $h['description'] ?? '',
                'date' => $dateStr,
            ];
        }, $folder->history);

        $isManual = (bool) $folder->creationMethod; // Supposé disponible via la relation d'entité

        $workspaceRemainingMinutes = $folder->workspace->getWorkspaceRemainingMinutes();

        // 5. Instanciation du ViewDTO
        return new ComplianceFolderShowResponse(
            id: (string) $folder->id,
            slugId: $folder->slugId,
            workspaceName: $folder->workspace->name,
            workspaceEmail: $folder->workspace->email,
            reference: $folder->reference,
            statusValue: $folder->status->value,
            statusLabel: $folder->status->getLabel(),
            isManual: $isManual,
            isKyb: $isKyb,
            isDraft: $isDraft,
            isArchived: $isArchived,
            isAcceptedRecording: $folder->isAcceptRecording,
            method: $folder->creationMethod,
            headerTitle: $headerTitle,
            headerSubtitle: $headerSubtitle,
            contactName: $contactName,
            workspaceRemainingMinutes: $workspaceRemainingMinutes,
            companyDocuments: $companyDocs,
            individualDocuments: $individualDocs,
            stakeholders: [], // À mapper avec ton entité réelle
            history: $historyFormatted,
            contactFirstName: $contactFirstName,
            contactLastName: $contactLastName, // À mapper de la même manière si tu as l'entité Stakeholder
            contactEmail: $contactEmail,
            type: $type,
            contactAddress: $contactAddress,
            postMeetingReport: $folder->postMeetingReport,
        );
    }
}
