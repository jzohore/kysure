<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Event\FlashDraftDiscardedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;

use function Symfony\Component\Clock\now;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Journal AMF de l'abandon d'un brouillon flash : même type d'audit que la
 * suppression par le cabinet, mais l'acteur est un libellé système.
 */
#[AsEventListener]
final readonly class AuditLogFlashDraftDiscardedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $complianceFolderRepository,
    ) {
    }

    public function __invoke(FlashDraftDiscardedEvent $event): void
    {
        $folder = $this->complianceFolderRepository->findOneBySlugId($event->folderSlugId);
        if (!$folder instanceof ComplianceFolder) {
            return; // dossier purgé entre-temps : rien à rattacher
        }

        $workspace = $folder->workspace;

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::KYC_FOLDER_DELETED,
            payload: [
                'folder_reference' => $folder->reference,
                'workspace_name' => $workspace->name,
                'actor_name' => $event->actorName,
                'actor_email' => null,
                'reason' => $event->reason,
                'creation_method' => $folder->creationMethod,
                'generated_at' => now()->format(\DateTimeInterface::ATOM),
            ],
            workspace: $workspace,
        ));
    }
}
