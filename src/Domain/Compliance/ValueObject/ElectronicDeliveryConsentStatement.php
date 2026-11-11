<?php

declare(strict_types=1);

namespace App\Domain\Compliance\ValueObject;

/**
 * Texte de l'avenant au DER par lequel un client qui a accusé une version antérieure du DER
 * accepte la remise électronique de ses documents (art. 3 du règlement délégué 2017/565).
 * Même engagement que la section 7 du DER et que {@see DerStatement} depuis la version
 * 2026-11-10 ; figé sur chaque {@see \App\Domain\Compliance\Entity\ElectronicDeliveryConsent}.
 *
 * ⚠️ Formulation provisoire — à faire valider par un avocat en droit de la distribution financière.
 */
final readonly class ElectronicDeliveryConsentStatement
{
    public const string VERSION = '2026-11-11';

    public const string TEXT = "J'accepte de recevoir les documents réglementaires de mon conseiller, dont mes rapports d'adéquation, "
        . 'sur support durable électronique dans mon espace sécurisé, avec un avis par e-mail à chaque nouveau document. '
        . 'Je peux à tout moment demander à les recevoir sur papier, sans frais.';

    public function __construct(
        public string $text = self::TEXT,
        public string $version = self::VERSION,
    ) {
    }

    public static function current(): self
    {
        return new self();
    }
}
