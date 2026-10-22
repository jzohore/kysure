<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr;

use App\Infrastructure\Ocr\AmazonTextractProvider;
use App\Infrastructure\Ocr\FakeOcrProvider;
use App\Infrastructure\Ocr\HybridOcrProvider;
use App\Infrastructure\Ocr\LocalOcrProvider;
use App\Infrastructure\Ocr\MindeeProvider;
use App\Infrastructure\Ocr\OcrFactory;
use App\Infrastructure\Ocr\OcrServiceClient;
use Aws\Textract\TextractClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class OcrFactoryTest extends TestCase
{
    #[DataProvider('modes')]
    public function testEachModeResolvesItsProvider(string $mode, string $expectedClass): void
    {
        self::assertInstanceOf($expectedClass, $this->factory()->create($mode));
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function modes(): iterable
    {
        yield 'Textract' => ['real', AmazonTextractProvider::class];
        yield 'Mindee' => ['mindee', MindeeProvider::class];
        yield 'service local' => ['local', LocalOcrProvider::class];
        yield 'hybride' => ['hybrid', HybridOcrProvider::class];
        yield 'mock' => ['mock', FakeOcrProvider::class];
        yield 'mode inconnu → fake (jamais d\'appel payant par défaut)' => ['n-importe-quoi', FakeOcrProvider::class];
    }

    private function factory(): OcrFactory
    {
        $ocrService = new OcrServiceClient(new MockHttpClient(), new NullLogger(), 'http://ocr.test:8000', 'test-token-0123456789abcdef');
        $textract = new AmazonTextractProvider(
            new TextractClient(['region' => 'eu-west-3', 'version' => 'latest', 'credentials' => ['key' => 'k', 'secret' => 's']]),
            new NullLogger(),
            $ocrService,
        );
        $local = new LocalOcrProvider($ocrService);

        return new OcrFactory(
            new MindeeProvider([]),
            new FakeOcrProvider(),
            $textract,
            $local,
            new HybridOcrProvider($local, $textract, new NullLogger()),
        );
    }
}
