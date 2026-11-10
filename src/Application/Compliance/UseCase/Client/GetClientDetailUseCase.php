<?php

declare(strict_types=1);

namespace App\Application\Compliance\UseCase\Client;

use App\Application\Compliance\DTO\Response\ClientDetailDto;
use App\Application\Compliance\DTO\Response\ClientFolderSummaryDto;
use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Enum\ComplianceFolderStatus;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Kyc\Enum\DocumentStatus;
use App\Domain\User\Entity\Client;
use App\Domain\User\Entity\ClientWorkspaceRelation;
use App\Domain\User\Exception\ClientNotFoundException;
use App\Domain\User\Repository\ClientRepositoryInterface;
use App\Domain\Workspace\Repository\WorkspaceMemberRepositoryInterface;
use App\Domain\Workspace\Service\CurrentUserProvider;
use App\Domain\Workspace\Service\CurrentWorkspaceProvider;

readonly class GetClientDetailUseCase
{
    public function __construct(
        private ClientRepositoryInterface $clientRepository,
        private CurrentWorkspaceProvider $workspaceProvider,
        private CurrentUserProvider $userProvider,
        private WorkspaceMemberRepositoryInterface $workspaceMemberRepository,
    ) {
    }

    public function __invoke(string $clientSlugId): ClientDetailDto
    {
        $workspace = $this->workspaceProvider->getWorkspace();

        $client = $this->clientRepository->findOneBySlugIdAndWorkspace($clientSlugId, $workspace);
        if (!$client instanceof Client) {
            throw ClientNotFoundException::withEmail($clientSlugId);
        }

        $folders = [];
        foreach ($client->complianceFolders as $folder) {
            if ($folder->workspace !== $workspace || ComplianceFolderStatus::DELETED === $folder->status) {
                continue;
            }
            $folders[] = $folder;
        }

        usort($folders, static fn (ComplianceFolder $a, ComplianceFolder $b): int => $b->createdAt <=> $a->createdAt);

        $summaries = array_map($this->summarize(...), $folders);

        // Retrait possible : aucun dossier ne porte de preuve, aucun verrou.
        $blockedReason = null;
        foreach ($folders as $folder) {
            if ($folder->isUnderLegalHold) {
                $blockedReason = 'Un verrou de litige est posé sur un dossier de ce client.';
                break;
            }
            if ($folder->carriesEvidence()) {
                $blockedReason = 'Ce client a au moins un dossier engagé (DER ou pièce transmise) : vous pouvez clôturer la relation, pas le retirer.';
                break;
            }
        }
        $canBeRemoved = null === $blockedReason;

        $canCloseRelationship = false;
        foreach ($folders as $folder) {
            if (null === $folder->relationshipEndedAt && $folder->carriesEvidence()) {
                $canCloseRelationship = true;
                break;
            }
        }

        $firstEngaged = null;
        foreach (array_reverse($folders) as $folder) {
            if ($folder->carriesEvidence()) {
                $firstEngaged = $folder->createdAt;
                break;
            }
        }

        $activeCount = 0;
        $closedCount = 0;
        foreach ($folders as $folder) {
            if ($folder->relationshipEndedAt instanceof \DateTimeImmutable || ComplianceFolderStatus::ARCHIVED === $folder->status) {
                ++$closedCount;
            } else {
                ++$activeCount;
            }
        }

        // Suppression réelle du compte : possible uniquement si le client n'a
        // AUCUN dossier nulle part (aucun cabinet, statut supprimé inclus) et
        // n'est rattaché qu'à ce seul cabinet.
        $removalDeletesAccount = $canBeRemoved
            && $client->complianceFolders->isEmpty()
            && 1 === $client->workspaces->count();

        // Autorisation « admin du cabinet » : le rôle Symfony n'est pas fiable
        // (le fondateur du cabinet ne l'a pas dans User::$roles) — on interroge
        // l'appartenance réelle, comme le font les use cases d'écriture.
        $isWorkspaceAdmin = $this->workspaceMemberRepository->isUserAdminOfWorkspace(
            $this->userProvider->getUser(),
            $workspace,
        );

        // Relation non confirmée (aucun DER accusé) : on n'expose QUE le nom
        // saisi par ce cabinet. Jamais l'e-mail, le téléphone ni l'ancienneté du
        // compte, qui peuvent appartenir à un autre cabinet.
        $relation = $client->relationWith($workspace);
        $pendingRelation = $relation instanceof ClientWorkspaceRelation && $relation->isPending() ? $relation : null;
        $isPending = $pendingRelation instanceof ClientWorkspaceRelation;

        return new ClientDetailDto(
            slugId: $client->slugId,
            fullName: $pendingRelation instanceof ClientWorkspaceRelation ? $pendingRelation->invitedFullName() : $client->getFullName(),
            email: $isPending ? '' : $client->email,
            phoneNumber: $isPending ? null : $client->phoneNumber,
            isActif: $client->isActiveFor($workspace),
            pendingConfirmation: $isPending,
            createdAtFormatted: $isPending ? '' : $client->createdAt->format('d/m/Y'),
            clientSinceFormatted: $firstEngaged?->format('d/m/Y'),
            folders: $summaries,
            activeFolderCount: $activeCount,
            closedFolderCount: $closedCount,
            isWorkspaceAdmin: $isWorkspaceAdmin,
            canBeRemoved: $canBeRemoved,
            removalDeletesAccount: $removalDeletesAccount,
            canCloseRelationship: $canCloseRelationship,
            removalBlockedReason: $blockedReason,
        );
    }

    private function summarize(ComplianceFolder $folder): ClientFolderSummaryDto
    {
        $derDocument = null;
        $documentCount = 0;
        $validatedCount = 0;

        foreach ($folder->documents as $document) {
            if (DocumentType::DER === $document->type) {
                $derDocument = $document;

                continue;
            }
            ++$documentCount;
            if (DocumentStatus::VALID === $document->status) {
                ++$validatedCount;
            }
        }

        return new ClientFolderSummaryDto(
            slugId: $folder->slugId,
            reference: $folder->reference,
            type: $folder instanceof BusinessFolder ? 'business' : 'individual',
            statusValue: $folder->status->value,
            statusLabel: $folder->status->getLabel(),
            openedAt: $folder->createdAt,
            documentCount: $documentCount,
            validatedDocumentCount: $validatedCount,
            hasDer: $derDocument instanceof ComplianceDocument,
            derAcknowledged: $derDocument instanceof ComplianceDocument && $derDocument->hasAcknowledgementInForce(),
            relationshipEnded: $folder->relationshipEndedAt instanceof \DateTimeImmutable,
            purgeDueAtFormatted: $folder->purgeDueAt?->format('d/m/Y'),
            underLegalHold: $folder->isUnderLegalHold,
            isDraft: $folder->isDraft(),
            recentEvents: $this->recentEvents($folder),
        );
    }

    /**
     * Les derniers événements du dossier (source « système » du suivi), les plus
     * récents d'abord.
     *
     * @return list<array{title: string, description: string, at: string}>
     */
    private function recentEvents(ComplianceFolder $folder): array
    {
        $events = [];
        foreach (array_reverse($folder->history) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $saveAt = $entry['saveAt'] ?? null;
            $at = '';
            if ($saveAt instanceof \DateTimeInterface) {
                $at = $saveAt->format('d/m/Y H:i');
            } elseif (is_array($saveAt) && isset($saveAt['date']) && is_string($saveAt['date'])) {
                try {
                    $at = new \DateTimeImmutable($saveAt['date'])->format('d/m/Y H:i');
                } catch (\Exception) {
                }
            } elseif (is_string($saveAt)) {
                try {
                    $at = new \DateTimeImmutable($saveAt)->format('d/m/Y H:i');
                } catch (\Exception) {
                }
            }

            $events[] = [
                'title' => (string) ($entry['title'] ?? 'Évènement'),
                'description' => (string) ($entry['description'] ?? ''),
                'at' => $at,
            ];

            if (6 === count($events)) {
                break;
            }
        }

        return $events;
    }
}
