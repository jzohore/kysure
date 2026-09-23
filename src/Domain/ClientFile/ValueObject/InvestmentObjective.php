<?php

declare(strict_types=1);

namespace App\Domain\ClientFile\ValueObject;

use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use Webmozart\Assert\Assert;

final readonly class InvestmentObjective
{
    public function __construct(
        public InvestmentObjectiveType $type,
        public ObjectivePriority $priority,
        public InvestmentHorizon $horizon,
        public ?int $amountInCents = null,
    ) {
        if (null !== $amountInCents) {
            Assert::greaterThan($amountInCents, 0, 'Le montant envisagé d\'un objectif doit être positif.');
        }
    }

    /**
     * @return array{type: string, priority: string, horizon: string, amountInCents: int|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'priority' => $this->priority->value,
            'horizon' => $this->horizon->value,
            'amountInCents' => $this->amountInCents,
        ];
    }

    /**
     * @param array{type: string, priority: string, horizon: string, amountInCents: int|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            InvestmentObjectiveType::from($data['type']),
            ObjectivePriority::from($data['priority']),
            InvestmentHorizon::from($data['horizon']),
            $data['amountInCents'],
        );
    }
}
