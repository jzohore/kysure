<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\KnowledgeLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\ValueObject\FinancialKnowledgeAnswers;
use PHPUnit\Framework\TestCase;

final class FinancialKnowledgeAnswersTest extends TestCase
{
    public function testAverageScoreIsTheMeanOfAllFamilies(): void
    {
        $answers = FinancialKnowledgeAnswers::fromLevels([
            ProductFamily::OPCVM_ETF->value => KnowledgeLevel::BONNE,      // 3
            ProductFamily::TITRES_VIFS->value => KnowledgeLevel::LIMITEE,  // 1
            ProductFamily::ASSURANCE_VIE->value => KnowledgeLevel::EXPERTISE, // 4
            ProductFamily::IMMOBILIER_SCPI->value => KnowledgeLevel::INTERMEDIAIRE, // 2
            ProductFamily::PRODUITS_COMPLEXES->value => KnowledgeLevel::AUCUNE, // 0
        ]);

        self::assertSame(2.0, $answers->averageScore());
    }

    public function testLevelForReturnsTheAnsweredLevel(): void
    {
        $answers = FinancialKnowledgeAnswers::fromLevels($this->allAucune());

        self::assertSame(KnowledgeLevel::AUCUNE, $answers->levelFor(ProductFamily::OPCVM_ETF));
    }

    public function testRejectsAMissingFamily(): void
    {
        $levels = $this->allAucune();
        unset($levels[ProductFamily::PRODUITS_COMPLEXES->value]);

        $this->expectException(\InvalidArgumentException::class);

        FinancialKnowledgeAnswers::fromLevels($levels);
    }

    /**
     * @return array<string, KnowledgeLevel>
     */
    private function allAucune(): array
    {
        $levels = [];
        foreach (ProductFamily::cases() as $family) {
            $levels[$family->value] = KnowledgeLevel::AUCUNE;
        }

        return $levels;
    }
}
