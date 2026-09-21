<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\ValueObject;

use App\Domain\Suitability\Enum\LossReaction;
use App\Domain\Suitability\ValueObject\RiskToleranceAnswers;
use PHPUnit\Framework\TestCase;

final class RiskToleranceAnswersTest extends TestCase
{
    public function testAverageScoreOfTheLeastTolerantAnswersIsZero(): void
    {
        $answers = RiskToleranceAnswers::fromReactions(
            LossReaction::VEND_TOUT,
            LossReaction::VEND_TOUT,
            LossReaction::VEND_TOUT,
        );

        self::assertSame(0.0, $answers->averageScore());
    }

    public function testAverageScoreOfTheMostTolerantAnswersIsThree(): void
    {
        $answers = RiskToleranceAnswers::fromReactions(
            LossReaction::RENFORCE_LA_POSITION,
            LossReaction::RENFORCE_LA_POSITION,
            LossReaction::RENFORCE_LA_POSITION,
        );

        self::assertSame(3.0, $answers->averageScore());
    }

    public function testAverageScoreOfMixedAnswers(): void
    {
        $answers = RiskToleranceAnswers::fromReactions(
            LossReaction::NE_FAIT_RIEN,        // 2
            LossReaction::REDUIT_LA_POSITION,  // 1
            LossReaction::VEND_TOUT,           // 0
        );

        self::assertSame(1.0, $answers->averageScore());
    }
}
