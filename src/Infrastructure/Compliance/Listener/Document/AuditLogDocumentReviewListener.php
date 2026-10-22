<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Listener\Document;

use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Event\DocumentCorrectionsRequestedEvent;
use App\Domain\Compliance\Event\DocumentRejectedEvent;
use App\Domain\Compliance\Event\DocumentReviewCancelledEvent;
use App\Domain\Compliance\Event\DocumentValidatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Audit inaltérable des décisions du conseiller sur les pièces (AMF / LCB-FT) : qui, quand, quelle pièce, quel
 * motif. Les données extraites de la pièce n'y figurent jamais.
 */
readonly class AuditLogDocumentReviewListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
    ) {
    }

    #[AsEventListener]
    public function onValidated(DocumentValidatedEvent $event): void
    {
        $this->log(AuditEventType::KYC_DOCUMENT_VALIDATED, $event->document, $event->advisorName);
    }

    #[AsEventListener]
    public function onRejected(DocumentRejectedEvent $event): void
    {
        $this->log(AuditEventType::KYC_DOCUMENT_REJECTED, $event->document, $event->advisorName, [
            'reason_code' => $event->document->rejectionReasonCode?->value,
            'reason' => $event->document->rejectionReason,
        ]);
    }

    #[AsEventListener]
    public function onReviewCancelled(DocumentReviewCancelledEvent $event): void
    {
        $this->log(AuditEventType::KYC_DOCUMENT_REVIEW_CANCELLED, $event->document, $event->advisorName, [
            'cancelled_decision' => $event->cancelledDecision->value,
            'reason' => $event->reason,
        ]);
    }

    #[AsEventListener]
    public function onCorrectionsRequested(DocumentCorrectionsRequestedEvent $event): void
    {
        $workspace = $event->folder->workspace;
        Assert::notNull($workspace);

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::KYC_DOCUMENT_CORRECTIONS_REQUESTED,
            payload: [
                'folder_slug_id' => $event->folder->slugId,
                'document_ids' => array_map(static fn (ComplianceDocument $doc): ?string => $doc->id?->toString(), $event->documents),
                'document_types' => array_map(static fn (ComplianceDocument $doc): string => $doc->type->value, $event->documents),
                'actor_name' => $event->advisorName,
                'actor_type' => 'cgp',
            ],
            workspace: $workspace,
        ));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function log(AuditEventType $type, ComplianceDocument $document, string $advisorName, array $extra = []): void
    {
        $workspace = $document->folder->workspace;
        Assert::notNull($document->id);
        Assert::notNull($workspace);

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: $type,
            payload: [
                'document_id' => $document->id->toString(),
                'document_type' => $document->type->value,
                'folder_slug_id' => $document->folder->slugId,
                'actor_name' => $advisorName,
                'actor_type' => 'cgp',
                ...$extra,
            ],
            workspace: $workspace,
        ));
    }
}
