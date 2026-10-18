<?php

declare(strict_types=1);

namespace App\Tests\Domain\Kyc\Validator;

use App\Domain\Compliance\Entity\BusinessFolder;
use App\Domain\Compliance\Entity\ComplianceDocument;
use App\Domain\Compliance\Entity\ComplianceFolder;
use App\Domain\Compliance\Entity\IndividualFolder;
use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Kyc\Validator\DocumentValidator;
use App\Tests\Application\ReflectionHelperTrait;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DocumentValidatorTest extends TestCase
{
    use ClockSensitiveTrait;
    use ReflectionHelperTrait;

    private DocumentValidator $validator;

    protected function setUp(): void
    {
        self::mockTime('2026-10-19 10:00:00');
        $this->validator = new DocumentValidator();
    }

    private function individualFolder(?string $firstName = 'Jean', ?string $lastName = 'Dupont'): IndividualFolder
    {
        return $this->createEntityState(IndividualFolder::class, [
            'slugId' => 'comp_fol_1',
            'reference' => 'DOS-1',
            'history' => [],
            'firstName' => $firstName,
            'lastName' => $lastName,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function document(DocumentType $type, array $data, ?ComplianceFolder $folder = null): ComplianceDocument
    {
        return $this->createEntityState(ComplianceDocument::class, [
            'slugId' => 'comp_doc_1',
            'folder' => $folder ?? $this->individualFolder(),
            'type' => $type,
            'ocrData' => $data,
            'acknowledgements' => new ArrayCollection(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function idData(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'DUPONT',
            'first_name' => 'JEAN PAUL',
            'birth_date' => '1980-01-21',
            'date_of_expiry' => '2030-05-01',
            'mrz' => [
                'line_1' => 'IDFRA1234567890123<<<<<<<<<<<<',
                'line_2' => '8001215M3005018FRA<<<<<<<<<<6',
                'line_3' => 'DUPONT<<JEAN<PAUL<<<<<<<<<<<<<',
            ],
        ], $overrides);
    }

    /**
     * @param list<string> $findings
     */
    private function assertFindingMentions(string $needle, array $findings): void
    {
        foreach ($findings as $finding) {
            if (str_contains($finding, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        self::fail(sprintf('Aucun point de vigilance ne mentionne « %s » : %s', $needle, implode(' | ', $findings)));
    }

    // --- Données absentes -------------------------------------------------------

    public function testNoDataIsReportedAsAnExtractionFailure(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, []);

        self::assertCount(1, $this->validator->validate($document));
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function typesWithoutRulesProvider(): iterable
    {
        yield 'RIB' => [DocumentType::RIB];
        yield 'origine des fonds' => [DocumentType::SOURCE_OF_FUNDS];
        yield 'RBE' => [DocumentType::RBE];
        yield 'statuts' => [DocumentType::ARTICLES_OF_ASSOC];
    }

    #[DataProvider('typesWithoutRulesProvider')]
    public function testATypeWithoutRulesNeverProducesAFalseFinding(DocumentType $type): void
    {
        self::assertSame([], $this->validator->validate($this->document($type, ['amount' => '1200'])));
    }

    // --- Pièces d'identité -------------------------------------------------------

    public function testAConsistentIdCardRaisesNothing(): void
    {
        self::assertSame([], $this->validator->validate($this->document(DocumentType::ID_CARD, $this->idData())));
    }

    public function testIdentityIsCrossCheckedAgainstTheIndividualFolderWithoutStakeholder(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['last_name' => 'MARTIN']));

        $this->assertFindingMentions('nom sur la pièce (MARTIN)', $this->validator->validate($document));
    }

    public function testAccentsHyphensAndCaseAreIgnoredWhenComparingNames(): void
    {
        $folder = $this->individualFolder('Jean-François', 'Dupré');
        $document = $this->document(DocumentType::ID_CARD, $this->idData([
            'last_name' => 'DUPRE',
            'first_name' => 'JEAN FRANCOIS',
            'mrz' => ['line_1' => 'IDFRA<<<<<<<<<<<<<<<<<<<<<<<<<<<<', 'line_2' => '8001215M3005018FRA<<<<<<<<<<6', 'line_3' => 'DUPRE<<JEAN<FRANCOIS<<<<<<<<<<'],
        ]), $folder);

        self::assertSame([], $this->validator->validate($document));
    }

    public function testADeclaredFirstNameContainedInTheCardFirstNamesIsAccepted(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['first_name' => 'JEAN PAUL ANDRE']));

        self::assertSame([], $this->validator->validate($document));
    }

    public function testADifferentFirstNameIsReported(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['first_name' => 'MARCEL']));

        $this->assertFindingMentions('prénom sur la pièce (MARCEL)', $this->validator->validate($document));
    }

    public function testAnUnreadableNameIsASeparateFindingNotAFalseMismatch(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['last_name' => '']));

        $this->assertFindingMentions('Nom illisible', $this->validator->validate($document));
    }

    public function testNoIdentityReferenceDisablesTheCrossCheck(): void
    {
        $folder = $this->individualFolder(null, null);
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['last_name' => 'MARTIN', 'first_name' => 'MARCEL']), $folder);

        $findings = $this->validator->validate($document);

        foreach ($findings as $finding) {
            self::assertStringNotContainsString('Identité non correspondante', $finding);
        }
    }

    public function testAnExpiredCardIsReported(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['date_of_expiry' => '2026-10-18']));

        $this->assertFindingMentions('expiré', $this->validator->validate($document));
    }

    public function testACardExpiringTodayIsStillValid(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['date_of_expiry' => '2026-10-19']));

        self::assertSame([], $this->validator->validate($document));
    }

    public function testAMissingExpiryDateIsReportedForAnIdentityDocument(): void
    {
        $data = $this->idData();
        unset($data['date_of_expiry']);

        $this->assertFindingMentions("Date d'expiration introuvable", $this->validator->validate($this->document(DocumentType::ID_CARD, $data)));
    }

    public function testASingleSidePhotoWithoutMrzAsksForACompleteDocument(): void
    {
        $data = $this->idData();
        unset($data['mrz']);

        $this->assertFindingMentions('MRZ', $this->validator->validate($this->document(DocumentType::ID_CARD, $data)));
    }

    public function testAResidencePermitWithoutMrzIsNotReportedForIt(): void
    {
        $data = $this->idData();
        unset($data['mrz']);

        self::assertSame([], $this->validator->validate($this->document(DocumentType::RESIDENCE_PERMIT, $data)));
    }

    public function testALastNameAbsentFromTheMrzIsReportedAsAnInconsistency(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData([
            'mrz' => ['line_1' => 'IDFRA1234567890123<<<<<<<<<<<<', 'line_2' => '8001215M3005018FRA<<<<<<<<<<6', 'line_3' => 'LEROY<<JEAN<PAUL<<<<<<<<<<<<<<'],
        ]));

        $this->assertFindingMentions('bande optique', $this->validator->validate($document));
    }

    public function testABirthDateDifferentFromTheMrzIsReported(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData(['birth_date' => '1975-03-02']));

        $this->assertFindingMentions('date de naissance', $this->validator->validate($document));
    }

    public function testTheOldTwoLineCardFormatKeepsTheNameOnLineOne(): void
    {
        $document = $this->document(DocumentType::ID_CARD, $this->idData([
            'mrz' => ['line_1' => 'IDFRADUPONT<<<<<<<<<<<<<<<<<<<<<<<<<', 'line_2' => '1303753008192JEAN<<<<<<<<8001215M2'],
        ]));

        self::assertSame([], $this->validator->validate($document));
    }

    // --- Justificatif de domicile -------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function addressData(array $overrides = []): array
    {
        return array_merge([
            'holder_name' => 'M. Jean DUPONT',
            'address' => '12 rue des Lilas 75011 Paris',
            'document_date' => '2026-09-15',
        ], $overrides);
    }

    public function testARecentProofOfAddressInTheClientNameRaisesNothingAndNeverAsksForAnExpiryDate(): void
    {
        $findings = $this->validator->validate($this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData()));

        self::assertSame([], $findings);
    }

    public function testAProofOfAddressOlderThanThreeMonthsIsReported(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['document_date' => '2026-07-18']));

        $this->assertFindingMentions('plus de 3 mois', $this->validator->validate($document));
    }

    public function testAProofOfAddressExactlyThreeMonthsOldIsAccepted(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['document_date' => '2026-07-19']));

        self::assertSame([], $this->validator->validate($document));
    }

    public function testAMissingDocumentDateIsReported(): void
    {
        $data = $this->addressData();
        unset($data['document_date']);

        $this->assertFindingMentions('Date du justificatif introuvable', $this->validator->validate($this->document(DocumentType::PROOF_OF_ADDRESS, $data)));
    }

    public function testAHolderDifferentFromTheClientIsReported(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['holder_name' => 'Mme Sophie LEROY']));

        $this->assertFindingMentions('LEROY', $this->validator->validate($document));
    }

    public function testACoupleHolderContainingTheClientNameIsAccepted(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['holder_name' => 'M. et Mme DUPONT']));

        self::assertSame([], $this->validator->validate($document));
    }

    public function testAnOcrTypoInTheHolderNameIsTolerated(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['holder_name' => 'Jean DUPONI']));

        self::assertSame([], $this->validator->validate($document));
    }

    public function testAnUnreadableAddressIsReported(): void
    {
        $document = $this->document(DocumentType::PROOF_OF_ADDRESS, $this->addressData(['address' => '']));

        $this->assertFindingMentions('Adresse illisible', $this->validator->validate($document));
    }

    // --- Extrait K-bis -------------------------------------------------------------

    private function businessFolder(): BusinessFolder
    {
        return $this->createEntityState(BusinessFolder::class, [
            'slugId' => 'comp_fol_2',
            'reference' => 'DOS-2',
            'history' => [],
            'companyName' => 'Conibi SAS',
            'siret' => '42922568300012',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function kbisData(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'CONIBI',
            'registration_number' => '429 225 683 R.C.S. Bobigny',
            'document_date' => '2026-10-01',
        ], $overrides);
    }

    public function testAConsistentRecentKbisRaisesNothing(): void
    {
        $document = $this->document(DocumentType::KBIS, $this->kbisData(), $this->businessFolder());

        self::assertSame([], $this->validator->validate($document));
    }

    public function testAKbisOfAnotherCompanyIsReported(): void
    {
        $document = $this->document(DocumentType::KBIS, $this->kbisData(['company_name' => 'Autre Holding', 'registration_number' => '552 100 554']), $this->businessFolder());

        $findings = $this->validator->validate($document);

        $this->assertFindingMentions('dénomination', $findings);
        $this->assertFindingMentions('SIREN', $findings);
    }

    public function testAnOldKbisIsReportedAndNeverAsksForAnExpiryDate(): void
    {
        $document = $this->document(DocumentType::KBIS, $this->kbisData(['document_date' => '2026-05-02']), $this->businessFolder());

        $findings = $this->validator->validate($document);

        $this->assertFindingMentions('plus de 3 mois', $findings);
        foreach ($findings as $finding) {
            self::assertStringNotContainsString('expiration', $finding);
        }
    }
}
