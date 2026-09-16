<?php

declare(strict_types=1);

namespace App\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use Webmozart\Assert\Assert;

/**
 * Réponses au questionnaire de connaissances financières (§3.1) : un {@see KnowledgeLevel}
 * par {@see ProductFamily}. Toutes les familles doivent être renseignées — un questionnaire
 * partiel n'a pas de valeur probante.
 */
final readonly class FinancialKnowledgeAnswers
{
    /**
     * @param array<string, KnowledgeLevel> $levelsByFamily clé = ProductFamily::value
     */
    private function __construct(
        public array $levelsByFamily,
    ) {
        foreach (ProductFamily::cases() as $family) {
            Assert::keyExists(
                $this->levelsByFamily,
                $family->value,
                sprintf('Le niveau de connaissance pour "%s" est manquant.', $family->value),
            );
        }
    }

    /**
     * @param array<string, KnowledgeLevel> $levelsByFamily clé = ProductFamily::value
     */
    public static function fromLevels(array $levelsByFamily): self
    {
        return new self($levelsByFamily);
    }

    public function levelFor(ProductFamily $family): KnowledgeLevel
    {
        return $this->levelsByFamily[$family->value];
    }

    /**
     * Score brut moyen sur 5 familles, échelle 0 à 4 (celle de {@see KnowledgeLevel}).
     */
    public function averageScore(): float
    {
        $total = array_sum(array_map(
            static fn (KnowledgeLevel $level): int => $level->value,
            $this->levelsByFamily,
        ));

        return $total / \count(ProductFamily::cases());
    }
}
