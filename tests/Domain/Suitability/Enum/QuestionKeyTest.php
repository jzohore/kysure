<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Enum;

use App\Domain\Suitability\Enum\AssessmentAnswerType;
use App\Domain\Suitability\Enum\QuestionKey;
use PHPUnit\Framework\TestCase;

final class QuestionKeyTest extends TestCase
{
    public function testEveryQuestionHasANonEmptyLabel(): void
    {
        foreach (QuestionKey::cases() as $key) {
            self::assertNotSame('', $key->getLabel());
        }
    }

    public function testOnlyTheSustainabilityConstraintsQuestionIsOptional(): void
    {
        foreach (QuestionKey::cases() as $key) {
            if (QuestionKey::SUSTAINABILITY_CONSTRAINTS === $key) {
                self::assertFalse($key->isRequired());
            } else {
                self::assertTrue($key->isRequired(), $key->value . ' devrait être obligatoire');
            }
        }
    }

    public function testChoiceQuestionsExposeChoicesAndOthersDoNot(): void
    {
        $choiceTypes = [AssessmentAnswerType::SINGLE_CHOICE_INT, AssessmentAnswerType::SINGLE_CHOICE_STRING, AssessmentAnswerType::MULTI_CHOICE_STRING];

        foreach (QuestionKey::cases() as $key) {
            if (\in_array($key->answerType(), $choiceTypes, true)) {
                self::assertNotNull($key->choices(), $key->value . ' devrait exposer des choix');
                self::assertNotEmpty($key->choices());
            } else {
                self::assertNull($key->choices(), $key->value . ' ne devrait pas exposer de choix');
            }
        }
    }

    public function testEachDimensionHasAtLeastOneQuestion(): void
    {
        $dimensions = array_unique(array_map(
            static fn (QuestionKey $key): string => $key->getDimension()->value,
            QuestionKey::cases(),
        ));

        self::assertCount(5, $dimensions);
    }
}
