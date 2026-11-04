<?php

declare(strict_types=1);

namespace App\Infrastructure\Ocr;

use App\Domain\Compliance\Enum\DocumentType;
use App\Domain\Port\OcrProviderInterface;
use App\Infrastructure\Ocr\Parser\AbstractMindeeParser;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

readonly class MindeeProvider implements OcrProviderInterface
{
    /**
     * @param iterable<AbstractMindeeParser> $parsers
     */
    public function __construct(
        #[AutowireIterator('app.mindee_parser')]
        private iterable $parsers,
    ) {
    }

    public function extractData(DocumentType $type, string $filePath, ?string $backFilePath = null): array
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($type)) {
                return $parser->parse($filePath);
            }
        }

        throw new \DomainException("Aucun parser OCR configuré pour le document de type : {$type->value}");
    }
}
