<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Event\ComplianceFolderSubmittedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Piste d'audit de la soumission du dossier. L'entité refuse une seconde soumission (le dossier
 * n'est plus « en attente » une fois en revue) : pas de rejeu à dédoublonner.
 */
#[AsEventListener]
readonly class AuditLogComplianceFolderSubmittedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(ComplianceFolderSubmittedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->folderSlugId);
        Assert::notNull($folder, 'Dossier introuvable pour la piste d\'audit.');

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::KYC_FOLDER_SUBMITTED,
            payload: [
                'folder_slug_id' => $event->folderSlugId,
                'folder_reference' => $folder->reference,
                'submitted_at' => $folder->submittedAt?->format(\DateTimeInterface::ATOM),
                'actor_type' => $event->actorType,
                'actor_name' => $event->actorName,
            ],
            workspace: $folder->workspace,
        ));
    }
}
