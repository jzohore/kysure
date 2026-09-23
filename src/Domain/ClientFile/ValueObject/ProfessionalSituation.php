<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\ProfessionalStatus;
use Webmozart\Assert\Assert;

final readonly class ProfessionalSituation
{
    public function __construct(
        public ProfessionalStatus $status,
        public ?string $profession = null,
        public ?string $employer = null,
        public ?int $seniorityYears = null,
    ) {
        if (null !== $seniorityYears) {
            Assert::greaterThanEq($seniorityYears, 0, 'L\'ancienneté ne peut pas être négative.');
        }
    }
}
