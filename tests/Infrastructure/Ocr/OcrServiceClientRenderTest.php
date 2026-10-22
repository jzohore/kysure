<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr;

use App\Infrastructure\Ocr\Exception\OcrServiceUnavailableException;
use App\Infrastructure\Ocr\OcrServiceClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Rendu PDF → JPEG par le service isolé, pour Textract (qui n'accepte que des images).
 */
final class OcrServiceClientRenderTest extends TestCase
{
    private const string TOKEN = 'test-token-0123456789abcdef';
    // Octets JPEG synthétiques : en-tête SOI suffisant pour le contrôle de format.
    private const string JPEG_PAGE_1 = "\xFF\xD8\xFF\xE0page-1";
    private const string JPEG_PAGE_2 = "\xFF\xD8\xFF\xE0page-2";

    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ocr_render_');
        self::assertIsString($file);
        file_put_contents($file, "%PDF-1.4 synthetic-id-card\n");
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReturnsTheRenderedJpegPagesAsBinary(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => implode("\n", $options['headers'])];

            return new MockResponse(json_encode(['pages' => [base64_encode(self::JPEG_PAGE_1), base64_encode(self::JPEG_PAGE_2)]], \JSON_THROW_ON_ERROR));
        });

        $pages = new OcrServiceClient($client, new SpyLogger(), 'http://ocr.test:8000', self::TOKEN)->renderPdfPages($this->file);

        self::assertSame([self::JPEG_PAGE_1, self::JPEG_PAGE_2], $pages);
        self::assertSame('POST', $captured['method']);
        self::assertSame('http://ocr.test:8000/render', $captured['url']);
        self::assertStringContainsString('Authorization: Bearer ' . self::TOKEN, $captured['headers']);
    }

    public function testACorruptedPdfIsADefinitiveDomainException(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unreadable_document');

        $this->client(new MockResponse('{"error":"unreadable_document"}', ['http_code' => 422]))->renderPdfPages($this->file);
    }

    public function testAServiceFailureIsRetryable(): void
    {
        $this->expectException(OcrServiceUnavailableException::class);

        $this->client(new MockResponse('{"error":"busy"}', ['http_code' => 503]))->renderPdfPages($this->file);
    }

    #[DataProvider('invalidRenderBodies')]
    public function testAnInvalidRenderingIsNeverSentToTextract(string $body): void
    {
        $this->expectException(OcrServiceUnavailableException::class);

        $this->client(new MockResponse($body))->renderPdfPages($this->file);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRenderBodies(): iterable
    {
        yield 'aucune page' => ['{"pages":[]}'];
        yield 'clé absente' => ['{}'];
        yield 'base64 invalide' => ['{"pages":["%%%"]}'];
        yield 'pas un JPEG' => ['{"pages":["' . base64_encode('<svg/>') . '"]}'];
        yield 'page non textuelle' => ['{"pages":[42]}'];
    }

    private function client(MockResponse $response): OcrServiceClient
    {
        return new OcrServiceClient(new MockHttpClient($response), new SpyLogger(), 'http://ocr.test:8000', self::TOKEN);
    }
}
