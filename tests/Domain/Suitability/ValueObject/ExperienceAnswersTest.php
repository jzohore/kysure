<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\ProductFamily;
use App\Domain\Suitability\Enum\TransactionFrequency;
use App\Domain\Suitability\ValueObject\ExperienceAnswers;
use PHPUnit\Framework\TestCase;

final class ExperienceAnswersTest extends TestCase
{
    public function testNoExperienceScoresAtTheBottom(): void
    {
        $answers = ExperienceAnswers::fromAnswers(
            productsHeld: [],
            transactionFrequency: TransactionFrequency::JAMAIS,
            approximateAmount: 0.0,
            experienceYears: 0,
            approximateTransactionCount: 0,
            hasExperiencedLosses: false,
        );

        self::assertSame(0.0, $answers->score());
    }

    public function testMaximalExperienceScoresAtTheTopDespiteTheLossesBonus(): void
    {
        $answers = ExperienceAnswers::fromAnswers(
            productsHeld: ProductFamily::cases(),
            transactionFrequency: TransactionFrequency::FREQUENTE,
            approximateAmount: 500000.0,
            experienceYears: 30,
            approximateTransactionCount: 900,
            hasExperiencedLosses: true,
        );

        // Le bonus « pertes déjà vécues » ne doit jamais faire dépasser le plafond de l'échelle.
        self::assertSame(4.0, $answers->score());
    }

    public function testHavingExperiencedLossesAddsABonusWithoutExceedingTheScale(): void
    {
        $withoutLosses = ExperienceAnswers::fromAnswers(
            productsHeld: [ProductFamily::OPCVM_ETF],
            transactionFrequency: TransactionFrequency::OCCASIONNELLE,
            approximateAmount: 1000.0,
            experienceYears: 2,
            approximateTransactionCount: 5,
            hasExperiencedLosses: false,
        );

        $withLosses = ExperienceAnswers::fromAnswers(
            productsHeld: [ProductFamily::OPCVM_ETF],
            transactionFrequency: TransactionFrequency::OCCASIONNELLE,
            approximateAmount: 1000.0,
            experienceYears: 2,
            approximateTransactionCount: 5,
            hasExperiencedLosses: true,
        );

        self::assertGreaterThan($withoutLosses->score(), $withLosses->score());
    }

    public function testRejectsANegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExperienceAnswers::fromAnswers(
            productsHeld: [],
            transactionFrequency: TransactionFrequency::JAMAIS,
            approximateAmount: -1.0,
            experienceYears: 0,
            approximateTransactionCount: 0,
            hasExperiencedLosses: false,
        );
    }
}
