<?php

declare(strict_types=1);

namespace App\Domain\AdequacyReport\ValueObject;

use App\Domain\AdequacyReport\Enum\MatchSeverity;
use Webmozart\Assert\Assert;

/**
 * Résultat du moteur d'adéquation : un constat par contrôle.
 */
final readonly class AdequacyEvaluation
{
    /**
     * @param list<MatchFinding> $findings
     */
    public function __construct(
        public array $findings,
    ) {
        Assert::notEmpty($findings, 'Une évaluation comporte au moins un constat.');
        Assert::allIsInstanceOf($findings, MatchFinding::class);
    }

    public function worstSeverity(): MatchSeverity
    {
        $worst = MatchSeverity::CONFORME;
        foreach ($this->findings as $finding) {
            if ($finding->severity->weight() > $worst->weight()) {
                $worst = $finding->severity;
            }
        }

        return $worst;
    }

    public function hasBlockingFinding(): bool
    {
        return MatchSeverity::BLOQUANT === $this->worstSeverity();
    }

    public function countBySeverity(MatchSeverity $severity): int
    {
        return \count(array_filter($this->findings, static fn (MatchFinding $finding): bool => $finding->severity === $severity));
    }

    /**
     * @return list<array{criterion: string, severity: string, message: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (MatchFinding $finding): array => $finding->toArray(), $this->findings);
    }

    /**
     * @param list<array{criterion: string, severity: string, message: string}> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(MatchFinding::fromArray(...), $data));
    }
}
