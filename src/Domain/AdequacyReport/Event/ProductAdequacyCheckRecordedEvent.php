<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\Event;

/**
 * Le CGP a enregistré une vérification d'adéquation produit (éventuellement malgré un point
 * bloquant, avec justification écrite).
 */
final readonly class ProductAdequacyCheckRecordedEvent
{
    public function __construct(
        public string $checkSlugId,
        public string $folderSlugId,
        public string $productName,
        public int $amountInCents,
        public string $worstSeverity,
        public bool $hasBlockingFinding,
        public ?string $justification,
        public string $checkedByName,
        public string $contentHash,
    ) {
    }
}
