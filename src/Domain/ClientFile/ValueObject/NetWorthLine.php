<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\AssetClass;
use Webmozart\Assert\Assert;

final readonly class NetWorthLine
{
    public function __construct(
        public AssetClass $assetClass,
        public int $amountInCents,
    ) {
        Assert::greaterThanEq($amountInCents, 0, 'Le montant d\'une poche patrimoniale ne peut pas être négatif.');
    }

    /**
     * @return array{assetClass: string, amountInCents: int}
     */
    public function toArray(): array
    {
        return ['assetClass' => $this->assetClass->value, 'amountInCents' => $this->amountInCents];
    }

    /**
     * @param array{assetClass: string, amountInCents: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(AssetClass::from($data['assetClass']), $data['amountInCents']);
    }
}
