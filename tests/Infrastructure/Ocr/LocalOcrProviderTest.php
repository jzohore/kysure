<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Infrastructure\Ocr\Exception\OcrServiceUnavailableException;
use App\Infrastructure\Ocr\LocalOcrProvider;
use App\Infrastructure\Ocr\OcrServiceClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LocalOcrProviderTest extends TestCase
{
    private const string TOKEN = 'test-token-0123456789abcdef';
    // Contenu SYNTHÉTIQUE : jamais de vraie pièce dans les tests.
    private const string FILE_CONTENT = "%PDF-1.4 synthetic-proof-of-address\n";

    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ocr_test_');
        self::assertIsString($file);
        file_put_contents($file, self::FILE_CONTENT);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReturnsTheNormalizedDataOfA200Response(): void
    {
        $payload = ['holder_name' => 'Mme Camille MARTIN', 'address' => '12 rue des Lilas 75011 PARIS', 'document_date' => '2026-01-12'];
        $provider = $this->provider(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        self::assertSame($payload, $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file));
    }

    public function testKeepsTheNestedMrzStructure(): void
    {
        $payload = ['last_name' => 'ALOIS', 'mrz' => ['line_1' => 'IDFRA<<', 'line_2' => '1303<<']];
        $provider = $this->provider(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)));

        self::assertSame($payload, $provider->extractData(DocumentType::ID_CARD, $this->file));
    }

    public function testAnEmptyJsonObjectMeansNothingReadable(): void
    {
        $provider = $this->provider(new MockResponse('{}'));

        self::assertSame([], $provider->extractData(DocumentType::KBIS, $this->file));
    }

    public function testSendsAnAuthenticatedMultipartRequestWithoutLeakingThePath(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => implode("\n", $options['headers']), 'body' => $this->bodyOf($options)];

            return new MockResponse('{}');
        });
        $provider = new LocalOcrProvider(new OcrServiceClient($client, new SpyLogger(), 'http://ocr.test:8000/', self::TOKEN));

        $provider->extractData(DocumentType::RIB, $this->file);

        self::assertSame('POST', $captured['method']);
        self::assertSame('http://ocr.test:8000/extract', $captured['url']);
        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $captured['headers']);
        self::assertStringContainsString('multipart/form-data', $captured['headers']);
        self::assertStringContainsString("name=\"type\"\r\n\r\nrib", $captured['body']);
        self::assertStringContainsString('filename=document', str_replace('"', '', $captured['body']));
        self::assertStringContainsString(self::FILE_CONTENT, $captured['body']);
        self::assertStringNotContainsString(basename($this->file), $captured['body']);
    }

    #[DataProvider('unreadableStatuses')]
    public function testAnUnreadableDocumentIsADefinitiveDomainException(int $status): void
    {
        $provider = $this->provider(new MockResponse('{"error":"unreadable_document"}', ['http_code' => $status]));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unreadable_document');

        $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unreadableStatuses(): iterable
    {
        yield 'illisible / non pris en charge' => [422];
        yield 'fichier trop volumineux' => [413];
        yield 'format non supporté' => [415];
    }

    #[DataProvider('technicalStatuses')]
    public function testATechnicalFailureIsRetryableAndNeverADomainException(int $status): void
    {
        $provider = $this->provider(new MockResponse('{"error":"internal_error"}', ['http_code' => $status]));

        try {
            $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);
            self::fail('Une exception réessayable était attendue.');
        } catch (OcrServiceUnavailableException $exception) {
            self::assertNotInstanceOf(\DomainException::class, $exception);
            self::assertStringContainsString((string) $status, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function technicalStatuses(): iterable
    {
        yield 'erreur serveur' => [500];
        yield 'service occupé' => [503];
        yield 'délai dépassé côté service' => [504];
        yield 'jeton refusé (mauvaise configuration)' => [401];
        yield 'trop de requêtes' => [429];
    }

    public function testATimeoutIsRetryable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Idle timeout reached'));
        $provider = new LocalOcrProvider(new OcrServiceClient($client, new SpyLogger(), 'http://ocr.test:8000', self::TOKEN));

        $this->expectException(OcrServiceUnavailableException::class);

        $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);
    }

    public function testAnUnreachableServiceKeepsTheOriginalCause(): void
    {
        $cause = new TransportException('Connection refused');
        $client = new MockHttpClient(static fn (): MockResponse => throw $cause);
        $provider = new LocalOcrProvider(new OcrServiceClient($client, new SpyLogger(), 'http://ocr.test:8000', self::TOKEN));

        try {
            $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);
            self::fail('Une exception était attendue.');
        } catch (OcrServiceUnavailableException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidSuccessBodyIsATechnicalFailure(string $body): void
    {
        $provider = $this->provider(new MockResponse($body));

        $this->expectException(OcrServiceUnavailableException::class);

        $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'pas du JSON' => ['<html>502 Bad Gateway</html>'];
        yield 'liste JSON' => ['["a","b"]'];
        yield 'scalaire JSON' => ['"ok"'];
    }

    public function testAnUnreadableLocalFileNeverReachesTheService(): void
    {
        $called = false;
        $client = new MockHttpClient(static function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('{}');
        });
        $provider = new LocalOcrProvider(new OcrServiceClient($client, new SpyLogger(), 'http://ocr.test:8000', self::TOKEN));

        try {
            $provider->extractData(DocumentType::PROOF_OF_ADDRESS, '/nonexistent/' . uniqid('', true));
            self::fail('Une exception était attendue.');
        } catch (OcrServiceUnavailableException) {
            self::assertFalse($called);
        }
    }

    public function testNoExtractedDataIsEverLogged(): void
    {
        $logger = new SpyLogger();
        $client = new MockHttpClient(new MockResponse('{"holder_name":"Mme Camille MARTIN","address":"12 rue des Lilas"}'));
        $provider = new LocalOcrProvider(new OcrServiceClient($client, $logger, 'http://ocr.test:8000', self::TOKEN));

        $provider->extractData(DocumentType::PROOF_OF_ADDRESS, $this->file);

        self::assertNotSame([], $logger->records);
        $logged = json_encode($logger->records, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('MARTIN', $logged);
        self::assertStringNotContainsString('Lilas', $logged);
        self::assertStringNotContainsString(self::TOKEN, $logged);
        self::assertStringContainsString('proof_of_address', $logged);
    }

    private function provider(MockResponse $response): LocalOcrProvider
    {
        return new LocalOcrProvider(new OcrServiceClient(new MockHttpClient($response), new SpyLogger(), 'http://ocr.test:8000', self::TOKEN));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bodyOf(array $options): string
    {
        $body = $options['body'];
        if (is_string($body)) {
            return $body;
        }

        $content = '';
        if ($body instanceof \Closure) {
            while ('' !== ($chunk = $body(16372))) {
                $content .= $chunk;
            }

            return $content;
        }

        foreach ($body as $chunk) {
            $content .= $chunk;
        }

        return $content;
    }
}
