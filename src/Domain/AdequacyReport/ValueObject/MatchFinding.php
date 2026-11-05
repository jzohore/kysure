<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use App\Domain\AdequacyReport\Enum\MatchCriterion;
use App\Domain\AdequacyReport\Enum\MatchSeverity;
use Webmozart\Assert\Assert;

/**
 * Constat du moteur d'adéquation sur un contrôle donné : gravité + explication lisible par le
 * CGP (et, au lot 5, citée telle quelle dans le rapport d'adéquation).
 */
final readonly class MatchFinding
{
    public function __construct(
        public MatchCriterion $criterion,
        public MatchSeverity $severity,
        public string $message,
    ) {
        Assert::stringNotEmpty(trim($message), 'Un constat doit être expliqué.');
    }

    public function isBlocking(): bool
    {
        return MatchSeverity::BLOQUANT === $this->severity;
    }

    /**
     * @return array{criterion: string, severity: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterion->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
        ];
    }

    /**
     * @param array{criterion: string, severity: string, message: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            MatchCriterion::from($data['criterion']),
            MatchSeverity::from($data['severity']),
            $data['message'],
        );
    }
}
