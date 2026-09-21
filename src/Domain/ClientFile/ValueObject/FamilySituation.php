<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\MaritalStatus;
use Webmozart\Assert\Assert;

final readonly class FamilySituation
{
    public function __construct(
        public MaritalStatus $maritalStatus,
        public int $childrenCount,
    ) {
        Assert::greaterThanEq($childrenCount, 0, 'Le nombre d\'enfants ne peut pas être négatif.');
    }
}
