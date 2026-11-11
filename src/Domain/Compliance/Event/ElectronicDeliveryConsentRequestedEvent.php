<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le CGP demande au client son accord pour la remise électronique (avenant au DER).
 */
final readonly class ElectronicDeliveryConsentRequestedEvent
{
    public function __construct(
        public string $folderSlugId,
        public string $requestedByName,
    ) {
    }
}
