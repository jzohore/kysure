<?php

declare(strict_types=1);

namespace App\Domain\Kyc\Validator;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Kyc\Entity\Stakeholder;

/**
 * Identité attendue pour une pièce : ce que le dossier déclare, contre quoi on recoupe ce que
 * l'OCR a lu. Toute valeur absente désactive le recoupement correspondant (on ne signale pas
 * une différence avec une référence qu'on ne connaît pas).
 */
final readonly class ExpectedIdentity
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $companyName = null,
        public ?string $siren = null,
    ) {
    }

    public static function forDocument(ComplianceDocument $document): self
    {
        // Parcours entreprise : la pièce d'un intervenant se recoupe avec cet intervenant.
        if ($document->stakeholder instanceof Stakeholder) {
            return new self(
                firstName: self::clean($document->stakeholder->firstName),
                lastName: self::clean($document->stakeholder->lastName),
            );
        }

        $folder = $document->folder;

        if ($folder instanceof BusinessFolder) {
            $siret = preg_replace('/\D/', '', (string) $folder->siret);

            return new self(
                companyName: self::clean($folder->companyName),
                siren: strlen((string) $siret) >= 9 ? substr((string) $siret, 0, 9) : null,
            );
        }

        // Dossier particulier : l'identité saisie sur le dossier, à défaut le client rattaché.
        if ($folder instanceof IndividualFolder) {
            $firstName = self::clean($folder->firstName) ?? self::clean($folder->client?->firstName);
            $lastName = self::clean($folder->lastName) ?? self::clean($folder->client?->lastName);

            return new self(firstName: $firstName, lastName: $lastName);
        }

        return new self();
    }

    private static function clean(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
