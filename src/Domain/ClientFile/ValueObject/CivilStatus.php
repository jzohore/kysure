<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\Civility;

final readonly class CivilStatus
{
    public function __construct(
        public Civility $civility,
        public \DateTimeImmutable $birthDate,
        public string $birthPlace,
        public string $nationality,
    ) {
    }
}
