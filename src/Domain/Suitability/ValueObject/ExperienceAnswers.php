<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\TransactionFrequency;
use Webmozart\Assert\Assert;

/**
 * Réponses au questionnaire d'expérience d'investissement (§3.2).
 */
final readonly class ExperienceAnswers
{
    private const int MAX_EXPERIENCE_YEARS_CONSIDERED = 15;
    private const float PAST_LOSSES_BONUS = 0.25;

    /**
     * @param list<ProductFamily> $productsHeld
     */
    private function __construct(
        public array $productsHeld,
        public TransactionFrequency $transactionFrequency,
        public float $approximateAmount,
        public int $experienceYears,
        public int $approximateTransactionCount,
        public bool $hasExperiencedLosses,
    ) {
        Assert::allIsInstanceOf($this->productsHeld, ProductFamily::class);
        Assert::greaterThanEq($this->approximateAmount, 0.0, 'Le montant approximatif ne peut pas être négatif.');
        Assert::greaterThanEq($this->experienceYears, 0, 'L\'ancienneté d\'expérience ne peut pas être négative.');
        Assert::greaterThanEq($this->approximateTransactionCount, 0, 'Le nombre de transactions ne peut pas être négatif.');
    }

    /**
     * @param list<ProductFamily> $productsHeld
     */
    public static function fromAnswers(
        array $productsHeld,
        TransactionFrequency $transactionFrequency,
        float $approximateAmount,
        int $experienceYears,
        int $approximateTransactionCount,
        bool $hasExperiencedLosses,
    ): self {
        return new self(
            $productsHeld,
            $transactionFrequency,
            $approximateAmount,
            $experienceYears,
            $approximateTransactionCount,
            $hasExperiencedLosses,
        );
    }

    /**
     * Score brut sur une échelle 0 à 4, homogène avec {@see FinancialKnowledgeAnswers::averageScore()},
     * construit à partir de 3 proxies (diversité des produits détenus, ancienneté, fréquence)
     * et d'un léger bonus si le client a déjà traversé une perte sans que ce ne soit demandé
     * comme un critère de tolérance (qui, lui, relève du §3.4).
     */
    public function score(): float
    {
        $productsHeldRatio = \count($this->productsHeld) / \count(ProductFamily::cases());
        $yearsRatio = min($this->experienceYears, self::MAX_EXPERIENCE_YEARS_CONSIDERED) / self::MAX_EXPERIENCE_YEARS_CONSIDERED;
        $frequencyRatio = $this->transactionFrequency->value / TransactionFrequency::FREQUENTE->value;

        $base = ($productsHeldRatio + $yearsRatio + $frequencyRatio) / 3 * 4;
        $bonus = $this->hasExperiencedLosses ? self::PAST_LOSSES_BONUS : 0.0;

        return min(4.0, $base + $bonus);
    }
}
