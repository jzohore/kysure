<?php

declare(strict_types=1);

namespace App\Domain\Scheduler\ValueObject;

final readonly class CommandRunResult
{
    private function __construct(
        public int $exitCode,
        public string $output,
    ) {
    }

    public static function fromExitCode(int $exitCode, string $output): self
    {
        return new self($exitCode, $output);
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
