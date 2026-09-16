<?php

declare(strict_types=1);

namespace App\Domain\Suitability\Event;

/**
 * Le CGP a figé le profil investisseur calculé d'un client : il devient opposable.
 */
final readonly class InvestorProfileValidatedEvent
{
    public function __construct(
        public string $profileSlugId,
        public string $clientSlugId,
        public int $version,
        public int $retainedProfileLevel,
        public bool $overridden,
        public string $validatedByName,
    ) {
    }
}
