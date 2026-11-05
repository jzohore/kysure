<?php

declare(strict_types=1);

namespace App\Infrastructure\AdequacyReport\Listener;

use App\Domain\AdequacyReport\Event\ProductAdequacyCheckRecordedEvent;
use App\Domain\AdequacyReport\Repository\ProductAdequacyCheckRepositoryInterface;
use App\Domain\AuditLog\Entity\AuditLog;
use App\Domain\AuditLog\Enum\AuditEventType;
use App\Domain\AuditLog\Repository\AuditLogRepositoryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webmozart\Assert\Assert;

/**
 * Piste d'audit AMF d'une vérification d'adéquation. Si un point bloquant a été passé outre,
 * une seconde entrée dédiée porte la justification écrite : c'est elle qu'un contrôleur
 * cherchera en premier, elle ne doit pas être noyée dans les vérifications ordinaires.
 *
 * Synchrone : l'event n'est émis qu'une fois par enregistrement (ligne en ajout seul), il n'y
 * a pas de rejeu possible à dédoublonner.
 */
#[AsEventListener]
readonly class AuditLogProductAdequacyCheckRecordedListener
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private ProductAdequacyCheckRepositoryInterface $checkRepository,
    ) {
    }

    public function __invoke(ProductAdequacyCheckRecordedEvent $event): void
    {
        $check = $this->checkRepository->findBySlugId($event->checkSlugId);
        Assert::notNull($check, 'Vérification d\'adéquation introuvable pour la piste d\'audit.');

        $payload = [
            'check_slug_id' => $event->checkSlugId,
            'folder_slug_id' => $event->folderSlugId,
            'product_name' => $event->productName,
            'amount_in_cents' => $event->amountInCents,
            'worst_severity' => $event->worstSeverity,
            'content_hash' => $event->contentHash,
            'actor_name' => $event->checkedByName,
            'actor_type' => 'cgp',
        ];

        $this->auditLogRepository->save(AuditLog::initiate(
            eventName: AuditEventType::ADEQUACY_CHECK_RECORDED,
            payload: $payload,
            workspace: $check->workspace,
        ));

        if ($event->hasBlockingFinding) {
            $this->auditLogRepository->save(AuditLog::initiate(
                eventName: AuditEventType::ADEQUACY_BLOCKING_FINDING_JUSTIFIED,
                payload: [...$payload, 'justification' => $event->justification],
                workspace: $check->workspace,
            ));
        }
    }
}
