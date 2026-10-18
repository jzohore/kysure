<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\Document;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Event\DocumentsRequestedEvent;
use App\Domain\Compliance\Repository\ComplianceFolderRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Trace inaltérable de la demande de pièces faite au client. L'acteur est le
 * système : la demande découle automatiquement de l'accusé de réception du DER.
 * L'événement n'est émis qu'une fois par accusé en vigueur (un rejeu de l'accusé
 * ne passe jamais ici), donc aucun doublon d'audit.
 */
#[AsEventListener]
readonly class AuditLogDocumentsRequestedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ComplianceFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function __invoke(DocumentsRequestedEvent $event): void
    {
        $folder = $this->folderRepository->findOneBySlugId($event->getFolderSlugId());
        Assert::notNull($folder, 'Le dossier lié à la demande de pièces est introuvable.');

        $workspace = $folder->workspace;
        Assert::notNull($workspace);

        $audit = AuditLog::initiate(
            eventName: AuditEventType::KYC_DOCUMENT_REQUESTED,
            payload: [
                'folder_slug_id' => $folder->slugId,
                'document_reference' => $folder->reference ?? 'N/A',
                'workspace_name' => $workspace->name,
                'actor_type' => 'system',
                'document_types' => $event->getDocumentTypes(),
                'requested_at' => $event->getRequestedAt()->format(\DateTimeInterface::ATOM),
            ],
            workspace: $workspace,
        );

        $this->auditLogRepository->save($audit);
    }
}
