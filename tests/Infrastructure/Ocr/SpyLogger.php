<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ocr;

use Psr\Log\AbstractLogger;

/**
 * Journal factice : conserve les enregistrements pour vérifier ce qui est (et n'est pas) écrit.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}
