<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Event;

/**
 * Un profil investisseur validé a été révoqué (remplacé par une nouvelle version à venir).
 */
final readonly class InvestorProfileRevokedEvent
{
    public function __construct(
        public string $profileSlugId,
        public string $clientSlugId,
        public int $version,
        public string $reason,
        public string $revokedByName,
    ) {
    }
}
