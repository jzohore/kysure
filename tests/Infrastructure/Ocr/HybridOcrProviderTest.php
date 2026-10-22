<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;
use App\Infrastructure\Ocr\Exception\OcrServiceUnavailableException;
use App\Infrastructure\Ocr\HybridOcrProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HybridOcrProviderTest extends TestCase
{
    private const string FILE = 'https://s3.test/tmp/doc.pdf';

    /** MRZ de démonstration ICAO 9303 (synthétique). */
    private const array LOCAL_MRZ = [
        'line_1' => 'P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<',
        'line_2' => 'L898902C36UTO7408122F1204159ZE184226B<<<<<10',
    ];

    #[DataProvider('localOnlyTypes')]
    public function testSimpleDocumentsNeverReachTextract(DocumentType $type): void
    {
        $data = ['holder_name' => 'Camille MARTIN'];
        $local = $this->providerReturning($data);
        $textract = $this->providerNeverCalled();

        self::assertSame($data, new HybridOcrProvider($local, $textract, new NullLogger())->extractData($type, self::FILE));
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function localOnlyTypes(): iterable
    {
        yield 'justificatif de domicile' => [DocumentType::PROOF_OF_ADDRESS];
        yield 'RIB' => [DocumentType::RIB];
    }

    public function testACompleteLocalKbisIsKeptWithoutPayingTextract(): void
    {
        $data = ['company_name' => 'EXEMPLE CONSEIL', 'registration_number' => '123 456 782', 'document_date' => '2026-01-08'];
        $provider = new HybridOcrProvider($this->providerReturning($data), $this->providerNeverCalled(), new NullLogger());

        self::assertSame($data, $provider->extractData(DocumentType::KBIS, self::FILE));
    }

    public function testKbisFallsBackToTextractWhenLocalCannotReadIt(): void
    {
        $local = $this->createStub(OcrProviderInterface::class);
        $local->method('extractData')->willThrowException(new \DomainException('illisible'));
        $textractData = ['company_name' => 'EXEMPLE CONSEIL (Textract)'];
        $textract = $this->createMock(OcrProviderInterface::class);
        $textract->expects($this->once())->method('extractData')->with(DocumentType::KBIS, self::FILE)->willReturn($textractData);

        self::assertSame($textractData, new HybridOcrProvider($local, $textract, new NullLogger())->extractData(DocumentType::KBIS, self::FILE));
    }

    #[DataProvider('unrecognisedKbis')]
    public function testKbisFallsBackToTextractWhenLocalRecognisedNothing(array $localData): void
    {
        $textractData = ['company_name' => 'EXEMPLE CONSEIL (Textract)'];
        $textract = $this->createMock(OcrProviderInterface::class);
        $textract->expects($this->once())->method('extractData')->willReturn($textractData);

        $provider = new HybridOcrProvider($this->providerReturning($localData), $textract, new NullLogger());

        self::assertSame($textractData, $provider->extractData(DocumentType::KBIS, self::FILE));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unrecognisedKbis(): iterable
    {
        yield 'rien lu' => [[]];
        yield 'seulement une date' => [['document_date' => '2026-01-08']];
    }

    public function testATechnicalLocalFailureOnKbisIsNeverRescuedByPayingTextract(): void
    {
        $local = $this->createStub(OcrProviderInterface::class);
        $local->method('extractData')->willThrowException(new OcrServiceUnavailableException('HTTP 503'));
        $provider = new HybridOcrProvider($local, $this->providerNeverCalled(), new NullLogger());

        $this->expectException(OcrServiceUnavailableException::class);

        $provider->extractData(DocumentType::KBIS, self::FILE);
    }

    #[DataProvider('identityTypes')]
    public function testIdentityDocumentsGoToTextractAndGetAValidatedMrzAsSecondOpinion(DocumentType $type): void
    {
        $textract = $this->providerReturning([
            'last_name' => 'ERIKSSON',
            'first_name' => 'ANNA',
            'mrz' => ['line_1' => 'P<UTOERIKS5ON<<ANNA', 'line_2' => 'L898902C36UTO74O8122F12O4159'],
        ]);
        $local = $this->providerReturning([
            'last_name' => 'ERIKSSON',
            'first_name' => 'ANNA MARIA',
            'birth_date' => '1974-08-12',
            'date_of_expiry' => '2031-04-15',
            'id_number' => 'L898902C3',
            'mrz' => self::LOCAL_MRZ,
        ]);

        $data = new HybridOcrProvider($local, $textract, new NullLogger())->extractData($type, self::FILE);

        // La MRZ non vérifiée de Textract est remplacée par celle à clés valides…
        self::assertSame(self::LOCAL_MRZ, $data['mrz']);
        // … les champs manquants sont comblés…
        self::assertSame('1974-08-12', $data['birth_date']);
        self::assertSame('2031-04-15', $data['date_of_expiry']);
        self::assertSame('L898902C3', $data['id_number']);
        // … mais ce que Textract a lu n'est jamais écrasé (le validateur verra un éventuel écart).
        self::assertSame('ANNA', $data['first_name']);
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function identityTypes(): iterable
    {
        yield 'CNI' => [DocumentType::ID_CARD];
        yield 'passeport' => [DocumentType::PASSPORT];
        yield 'titre de séjour' => [DocumentType::RESIDENCE_PERMIT];
    }

    public function testIdentityResultIsUntouchedWhenLocalFoundNoValidMrz(): void
    {
        $textractData = ['last_name' => 'ERIKSSON', 'mrz' => ['line_1' => 'X', 'line_2' => 'Y']];
        $local = $this->providerReturning(['last_name' => 'AUTRE', 'birth_date' => '1990-01-01']);

        $data = new HybridOcrProvider($local, $this->providerReturning($textractData), new NullLogger())
            ->extractData(DocumentType::ID_CARD, self::FILE);

        self::assertSame($textractData, $data);
    }

    #[DataProvider('anyLocalFailure')]
    public function testASecondOpinionFailureNeverBreaksASuccessfulTextractAnalysis(\Throwable $failure): void
    {
        $textractData = ['last_name' => 'ERIKSSON'];
        $local = $this->createStub(OcrProviderInterface::class);
        $local->method('extractData')->willThrowException($failure);

        $data = new HybridOcrProvider($local, $this->providerReturning($textractData), new NullLogger())
            ->extractData(DocumentType::PASSPORT, self::FILE);

        self::assertSame($textractData, $data);
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function anyLocalFailure(): iterable
    {
        yield 'service indisponible' => [new OcrServiceUnavailableException('timeout')];
        yield 'document illisible' => [new \DomainException('illisible')];
    }

    public function testATextractFailureOnIdentityIsPropagatedWithoutCallingLocal(): void
    {
        $textract = $this->createStub(OcrProviderInterface::class);
        $textract->method('extractData')->willThrowException(new \RuntimeException('AWS throttling'));
        $provider = new HybridOcrProvider($this->providerNeverCalled(), $textract, new NullLogger());

        $this->expectException(\RuntimeException::class);

        $provider->extractData(DocumentType::ID_CARD, self::FILE);
    }

    #[DataProvider('manualVerificationTypes')]
    public function testOtherTypesHaveNoOcrAndNothingIsCalled(DocumentType $type): void
    {
        $provider = new HybridOcrProvider($this->providerNeverCalled(), $this->providerNeverCalled(), new NullLogger());

        $this->expectException(\DomainException::class);

        $provider->extractData($type, self::FILE);
    }

    /**
     * @return iterable<string, array{DocumentType}>
     */
    public static function manualVerificationTypes(): iterable
    {
        yield 'origine des fonds' => [DocumentType::SOURCE_OF_FUNDS];
        yield 'statuts' => [DocumentType::ARTICLES_OF_ASSOC];
        yield 'DER' => [DocumentType::DER];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function providerReturning(array $data): OcrProviderInterface
    {
        $provider = $this->createStub(OcrProviderInterface::class);
        $provider->method('extractData')->willReturn($data);

        return $provider;
    }

    private function providerNeverCalled(): OcrProviderInterface
    {
        $provider = $this->createMock(OcrProviderInterface::class);
        $provider->expects($this->never())->method('extractData');

        return $provider;
    }
}
