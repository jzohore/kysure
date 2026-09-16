<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Suitability\Command;

use App\Domain\Suitability\Service\ScoringEngineV1;
use App\Infrastructure\Suitability\Command\ScoreSuitabilityCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ScoreSuitabilityCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester(new ScoreSuitabilityCommand(new ScoringEngineV1()));
    }

    public function testSampleOptionPrintsAValidAndCompleteJsonDocument(): void
    {
        $exitCode = $this->tester->execute(['--sample' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('knowledge', $decoded);
        self::assertArrayHasKey('experience', $decoded);
        self::assertArrayHasKey('tolerance', $decoded);
        self::assertArrayHasKey('capacity', $decoded);
        self::assertArrayHasKey('sustainability', $decoded);
    }

    public function testScoresAValidAnswersFileAndDisplaysTheRetainedProfile(): void
    {
        $file = $this->writeTempAnswersFile($this->validAnswers());

        $exitCode = $this->tester->execute(['file' => $file]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Profil retenu', $this->tester->getDisplay());

        unlink($file);
    }

    public function testFailsClearlyWhenNoFileAndNoSampleOptionAreGiven(): void
    {
        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testFailsClearlyOnAMissingFile(): void
    {
        $exitCode = $this->tester->execute(['file' => '/tmp/does-not-exist-suitability.json']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('introuvable', $this->tester->getDisplay());
    }

    public function testFailsClearlyOnAnIncompleteAnswersFile(): void
    {
        $answers = $this->validAnswers();
        unset($answers['sustainability']);
        $file = $this->writeTempAnswersFile($answers);

        $exitCode = $this->tester->execute(['file' => $file]);

        self::assertSame(Command::FAILURE, $exitCode);

        unlink($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function validAnswers(): array
    {
        return [
            'knowledge' => [
                'opcvm_etf' => 3,
                'titres_vifs' => 1,
                'assurance_vie' => 4,
                'immobilier_scpi' => 2,
                'produits_complexes' => 0,
            ],
            'experience' => [
                'productsHeld' => ['opcvm_etf', 'assurance_vie'],
                'transactionFrequency' => 2,
                'approximateAmount' => 50000,
                'experienceYears' => 8,
                'approximateTransactionCount' => 40,
                'hasExperiencedLosses' => true,
            ],
            'tolerance' => [
                'reactionToMinus10Percent' => 2,
                'reactionToMinus20Percent' => 1,
                'reactionToSignificantLoss' => 0,
            ],
            'capacity' => [
                'annualIncome' => 60000,
                'annualExpenses' => 35000,
                'netWorth' => 250000,
                'availableLiquidity' => 40000,
                'amountToInvest' => 20000,
                'horizon' => '5_8_ans',
            ],
            'sustainability' => [
                'preference' => 'sans_preference',
                'specificConstraints' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function writeTempAnswersFile(array $answers): string
    {
        $file = tempnam(sys_get_temp_dir(), 'suitability_test_');
        self::assertIsString($file);
        file_put_contents($file, json_encode($answers, \JSON_THROW_ON_ERROR));

        return $file;
    }
}
