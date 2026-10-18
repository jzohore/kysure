<?php

declare(strict_types=1);

namespace App\Application\Billing\DTO\Response;

/**
 * Une ligne de tarif affichée sur la vitrine publique (abonnement au siège ou pack de minutes).
 */
final readonly class PublicPricingOffer
{
    public function __construct(
        public string $label,
        public int $amountCents,
        public int $minutes,
        public int $minSeats = 1,
    ) {
    }

    /** « 99 » pour un montant rond, « 29,50 » sinon (espace insécable des milliers). */
    public function formattedAmount(): string
    {
        $euros = $this->amountCents / 100;

        return number_format($euros, 0 === $this->amountCents % 100 ? 0 : 2, ',', "\u{202F}");
    }
}
