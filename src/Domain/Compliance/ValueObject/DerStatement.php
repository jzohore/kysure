<?php

declare(strict_types=1);

namespace App\Domain\Compliance\ValueObject;

/**
 * Texte exact de l'attestation que le client coche pour accuser réception du DER,
 * avec sa version. Le texte est figé sur chaque {@see \App\Domain\Compliance\Entity\DerAcknowledgement}
 * : une preuve doit dire quel libellé a été accepté.
 *
 * Depuis la version 2026-11-10, l'attestation porte aussi l'accord du client pour recevoir
 * les documents réglementaires (dont le rapport d'adéquation) sur support durable
 * électronique, via l'espace sécurisé avec avis par e-mail (art. 3 du règlement délégué
 * 2017/565). Un accusé d'une version antérieure ne vaut pas cet accord.
 *
 * ⚠️ Formulation provisoire — à faire valider par un avocat en droit de la
 * distribution financière (art. 325-5 RG AMF, L.521-2 C. assur.).
 */
final readonly class DerStatement
{
    public const string VERSION = '2026-11-10';

    /** Première version de l'attestation qui inclut l'accord pour le support électronique. */
    public const string ELECTRONIC_DELIVERY_CONSENT_SINCE = '2026-11-10';

    public const string TEXT = "Je reconnais avoir reçu ce jour le Document d'Entrée en Relation (DER) du cabinet, "
        . 'en avoir pris connaissance, et en conserver une copie sur support durable. '
        . "J'accepte de recevoir les documents réglementaires de mon conseiller, dont mes rapports d'adéquation, "
        . 'sur support durable électronique dans mon espace sécurisé, avec un avis par e-mail à chaque nouveau document.';

    public function __construct(
        public string $text = self::TEXT,
        public string $version = self::VERSION,
    ) {
    }

    public static function current(): self
    {
        return new self();
    }

    /** Les versions sont des dates ISO : la comparaison de chaînes suit l'ordre chronologique. */
    public static function coversElectronicDelivery(string $version): bool
    {
        return $version >= self::ELECTRONIC_DELIVERY_CONSENT_SINCE;
    }
}
