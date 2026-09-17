<?php

declare(strict_types=1);

namespace App\Application\Portal\UseCase;

use App\Application\Portal\DTO\ActiveFolderDto;
use App\Application\Portal\DTO\ClientCabinetRelationshipDto;
use App\Application\Portal\DTO\ClientDashboardDto;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Repository\ComplianceDocumentRepositoryInterface;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use App\Domain\Suitability\Entity\InvestorProfileAssessment;
use App\Domain\Suitability\Entity\ValidatedInvestorProfile;
use App\Domain\Suitability\Enum\InvestorProfileDashboardStatus;
use App\Domain\Suitability\Repository\InvestorProfileAssessmentRepositoryInterface;
use App\Domain\Suitability\Repository\ValidatedInvestorProfileRepositoryInterface;
use App\Domain\User\Entity\Client;
use App\Domain\User\Enum\ClientPortalStatus;
use Psr\Log\LoggerInterface;

/**
 * Un client peut être suivi par plusieurs cabinets à la fois
 * ({@see Client::$workspaces}) : le tableau de bord restitue une
 * relation par cabinet ayant un dossier actif, jamais une seule mise en avant arbitraire —
 * sinon un client avec deux cabinets ne verrait même pas l'existence du second depuis
 * l'accueil.
 */
readonly class GetClientDashboardUseCase
{
    public function __construct(
        private ComplianceDocumentRepositoryInterface $documentRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
        private InvestorProfileAssessmentRepositoryInterface $assessmentRepository,
        private ValidatedInvestorProfileRepositoryInterface $validatedProfileRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Client $client): ClientDashboardDto
    {
        $activeFolders = $this->folderRepository->findAllActiveForClient($client);

        // 🚨 INVARIANT MÉTIER KYSURE : si le client se connecte, son DER est signé auprès
        // d'au moins un cabinet. Il DOIT avoir au moins un dossier actif.
        if ([] === $activeFolders) {
            $this->logger->critical('Anomalie DDD : Un client sans dossier actif a réussi à se connecter au portail.', [
                'client_id' => $client->slugId,
            ]);

            throw new \LogicException(sprintf('Incohérence de domaine : Aucun dossier actif trouvé pour le client %s.', $client->slugId));
        }

        $relationships = array_map(
            fn (ComplianceFolder $folder): ClientCabinetRelationshipDto => $this->buildRelationship($client, $folder),
            $activeFolders,
        );

        return new ClientDashboardDto(
            clientFirstName: $client->firstName,
            cabinetRelationships: $relationships,
        );
    }

    private function buildRelationship(Client $client, ComplianceFolder $folder): ClientCabinetRelationshipDto
    {
        $workspace = $folder->workspace;
        $portalStatus = ClientPortalStatus::fromFolderStatus($folder->status);

        // Compteur de pièces en attente scopé à CE dossier : sinon un cabinet afficherait
        // aussi les pièces demandées par un autre cabinet du même client.
        $pendingDocs = $this->documentRepository->countPendingForFolder($folder);

        $activeFolderDto = new ActiveFolderDto(
            id: $folder->slugId ?? (string) $folder->id,
            title: $folder->title ?? 'Dossier de Conformité (KYC)',
            openedAtFormatted: $folder->createdAt->format('d/m/Y'),
            status: $portalStatus,
            workspaceName: $workspace->name,
        );

        // Statut du profil investisseur, scopé à CE cabinet : chacun garde son propre
        // historique (voir ValidatedInvestorProfile), jamais mélangés entre eux.
        $investorProfileStatus = match (true) {
            $this->validatedProfileRepository->findInForceByClient($client, $workspace) instanceof ValidatedInvestorProfile => InvestorProfileDashboardStatus::VALIDATED,
            $this->assessmentRepository->findLatestSubmittedForClient($client, $workspace) instanceof InvestorProfileAssessment => InvestorProfileDashboardStatus::SUBMITTED,
            $this->assessmentRepository->findActiveDraftForClient($client, $workspace) instanceof InvestorProfileAssessment => InvestorProfileDashboardStatus::IN_PROGRESS,
            default => InvestorProfileDashboardStatus::NOT_STARTED,
        };

        return new ClientCabinetRelationshipDto(
            activeFolder: $activeFolderDto,
            cabinetContactEmail: $workspace->email,
            pendingDocumentsCount: $pendingDocs,
            investorProfileStatus: $investorProfileStatus,
        );
    }
}
