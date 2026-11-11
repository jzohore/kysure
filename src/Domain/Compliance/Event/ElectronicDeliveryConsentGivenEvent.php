<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Event;

/**
 * Le client a accepté la remise électronique de ses documents (avenant au DER).
 */
final readonly class ElectronicDeliveryConsentGivenEvent
{
    public function __construct(
        public string $consentSlugId,
        public string $folderSlugId,
        public string $clientSlugId,
        public string $statementVersion,
    ) {
    }
}
