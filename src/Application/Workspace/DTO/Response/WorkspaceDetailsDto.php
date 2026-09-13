<?php

declare(strict_types=1);

namespace App\Application\Workspace\DTO\Response;

use App\Domain\Support\Entity\SupportThread;
use App\Domain\Support\Enum\SupportThreadStatus;
use App\Domain\Workspace\Entity\Workspace;
use App\Domain\Workspace\Entity\WorkspaceMember;

final readonly class WorkspaceDetailsDto
{
    public function __construct(
        public string $slugId,
        public string $name,
        public ?string $legalName,
        public ?string $siret,
        public ?string $etatAdministratif,
        public ?string $address,
        public ?string $industry,
        public string $type,
        public bool $isActive,
        public bool $isSiretValid,
        public int $membersCount,
        public int $foldersCount,
        public int $clientsCount,
        public int $auditLogsCount,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $suspendedAt,
        public ?\DateTimeImmutable $verifySiretLastAttemptedAt,
        public ?string $suspensionReason,

        // --- DONNÉES D'ABONNEMENT STRIPE ---
        public ?string $subscriptionPlan,
        public ?string $subscriptionStatus,
        public ?\DateTimeImmutable $subscriptionStart,
        public ?\DateTimeImmutable $subscriptionEnd,
        public bool $subscriptionCancelAtPeriodEnd,
        public int $subscriptionSeatsCount,
        /** Prix par siège, en euros, lu en direct sur Stripe. */
        public ?float $subscriptionBasePrice,

        // --- DONNÉES DU PROFIL RÉGLEMENTAIRE (KYC du Cabinet) ---
        public ?string $oriasNumber,
        public ?string $professionalAssociation,
        public ?string $rcProInsurer,
        public ?string $rcProPolicyNumber,
        public ?\DateTimeImmutable $lastCheckOrias,
        public bool $isIndependent,
        public bool $isValidOrias,

        // --- QUOTAS ---
        public int $trialDossiersRemaining,
        public int $remainingMeetingMinutes,

        // --- SUPPORT ---
        public int $openTicketsCount,
        public int $closedTicketsCount,

        /** E-mail du propriétaire du cabinet, pour la connexion support. */
        public ?string $ownerEmail = null,
    ) {
    }

    public static function fromEntity(Workspace $workspace, ?float $subscriptionBasePrice = null): self
    {
        $openTickets = $workspace->supportThread->filter(
            static fn (SupportThread $thread): bool => SupportThreadStatus::OPEN === $thread->status
        )->count();

        $closedTickets = $workspace->supportThread->filter(
            static fn (SupportThread $thread): bool => SupportThreadStatus::RESOLVED === $thread->status
        )->count();

        // Propriétaire du cabinet : cible privilégiée d'une connexion support.
        // À défaut de propriétaire identifié, on retombe sur le premier membre.
        $owner = $workspace->members->findFirst(
            static fn (int $_i, WorkspaceMember $member): bool => $member->user->isOwner
        ) ?? $workspace->members->first();

        return new self(
            slugId: $workspace->slugId,
            name: $workspace->name,
            legalName: $workspace->legalName,
            siret: $workspace->siret,
            etatAdministratif: $workspace->etatAdministratif,
            address: $workspace->address,
            industry: $workspace->industry?->value,
            type: $workspace->type->value,
            isActive: $workspace->isActive,
            isSiretValid: $workspace->isSiretValid,
            membersCount: $workspace->members->count(),
            foldersCount: $workspace->folders->count(),
            clientsCount: $workspace->clients->count(),
            auditLogsCount: $workspace->auditLogs->count(),
            createdAt: $workspace->createdAt,
            suspendedAt: $workspace->suspendedAt,
            verifySiretLastAttemptedAt: $workspace->verifySiretLastAttemptedAt,
            suspensionReason: $workspace->suspensionReason,

            // --- Mapping de l'Abonnement ---
            subscriptionPlan: $workspace->subscription?->planReference,
            subscriptionStatus: $workspace->subscription?->status->value,
            subscriptionStart: $workspace->subscription?->currentPeriodStart,
            subscriptionEnd: $workspace->subscription?->currentPeriodEnd,
            // 🪄 Le '??' protège du null, on met donc une flèche simple '->'
            subscriptionCancelAtPeriodEnd: $workspace->subscription->cancelAtPeriodEnd ?? false,
            // 🪄 Le '??' protège du null, on met donc une flèche simple '->'
            subscriptionSeatsCount: $workspace->subscription->seatsCount ?? 0,
            subscriptionBasePrice: $subscriptionBasePrice,

            // --- Mapping du Profil Réglementaire ---
            // Le '?' est OBLIGATOIRE ici car il n'y a pas de '??' à la fin
            oriasNumber: $workspace->regulatoryProfile?->oriasNumber,
            professionalAssociation: $workspace->regulatoryProfile?->professionalAssociation,
            rcProInsurer: $workspace->regulatoryProfile?->rcProInsurer,
            rcProPolicyNumber: $workspace->regulatoryProfile?->rcProPolicyNumber,
            lastCheckOrias: $workspace->regulatoryProfile?->lastCheckOrias,

            // 🪄 Le '??' protège du null, on met donc une flèche simple '->'
            isIndependent: $workspace->regulatoryProfile->isIndependent ?? true,
            isValidOrias: $workspace->regulatoryProfile->isValidOrias ?? false,

            trialDossiersRemaining: $workspace->trialDossiersRemaining,
            remainingMeetingMinutes: $workspace->remainingMeetingMinutes(),

            openTicketsCount: $openTickets,
            closedTicketsCount: $closedTickets,

            ownerEmail: $owner instanceof WorkspaceMember ? $owner->user->email : null,
        );
    }
}
