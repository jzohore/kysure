<?php

declare(strict_types=1);

namespace App\Application\Compliance\DTO\Response;

readonly class ClientFolderSummaryDto
{
    /**
     * @param list<array{title: string, description: string, at: string}> $recentEvents
     */
    public function __construct(
        public string $slugId,
        public string $reference,
        public string $type, // 'individual' | 'business'
        public string $statusValue,
        public string $statusLabel,
        /** Formatée par Twig (filtre `date`), qui applique le fuseau de l'application. */
        public \DateTimeImmutable $openedAt,
        public int $documentCount,
        public int $validatedDocumentCount,
        public bool $hasDer,
        public bool $derAcknowledged,
        public bool $relationshipEnded,
        public ?string $purgeDueAtFormatted,
        public bool $underLegalHold,
        public bool $isDraft,
        public array $recentEvents = [],
    ) {
    }
}
