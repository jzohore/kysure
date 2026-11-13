<?php

declare(strict_types=1);

namespace App\Tests\Domain\Firm\Entity;

use App\Domain\Firm\Entity\RegulatoryProfile;
use App\Tests\Application\ReflectionHelperTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lot 7 : précisions du cabinet imprimées dans le DER (texte brut multiligne, borné).
 */
final class RegulatoryProfileDerAdditionalInformationTest extends TestCase
{
    use ReflectionHelperTrait;

    private function updateWith(?string $text, ?RegulatoryProfile $profile = null): RegulatoryProfile
    {
        $profile ??= $this->createEntityState(RegulatoryProfile::class, []);
        $profile->update(
            oriasNumber: '12345678',
            professionalAssociation: 'CNCGP',
            rcProInsurer: 'CGPA',
            rcProPolicyNumber: 'RC-12345',
            isIndependent: true,
            partners: [],
            derAdditionalInformation: $text,
        );

        return $profile;
    }

    public function testTextIsStoredTrimmedWithLineBreaksKept(): void
    {
        $profile = $this->updateWith("  Services : conseil en investissement.\r\nRémunération : honoraires.\rConflits : registre tenu.  ");

        self::assertSame(
            "Services : conseil en investissement.\nRémunération : honoraires.\nConflits : registre tenu.",
            $profile->derAdditionalInformation,
        );
    }

    #[DataProvider('emptyValues')]
    public function testEmptyTextClearsTheSection(?string $text): void
    {
        $profile = $this->updateWith('Texte précédent.');

        self::assertNull($this->updateWith($text, $profile)->derAdditionalInformation);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function emptyValues(): iterable
    {
        yield 'null' => [null];
        yield 'chaîne vide' => [''];
        yield 'blancs et sauts de ligne' => ["  \r\n\n  "];
    }

    public function testTextAtTheLimitIsAccepted(): void
    {
        $text = str_repeat('é', RegulatoryProfile::DER_ADDITIONAL_INFORMATION_MAX_LENGTH);

        self::assertSame($text, $this->updateWith($text)->derAdditionalInformation);
    }

    public function testTextBeyondTheLimitIsRejected(): void
    {
        $profile = $this->updateWith('Texte en place.');

        try {
            $this->updateWith(str_repeat('a', RegulatoryProfile::DER_ADDITIONAL_INFORMATION_MAX_LENGTH + 1), $profile);
            self::fail('Un texte trop long doit être refusé.');
        } catch (\InvalidArgumentException) {
            // Garde levée avant toute mutation : le profil reste intact.
            self::assertSame('Texte en place.', $profile->derAdditionalInformation);
        }
    }
}
