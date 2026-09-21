<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Service;

use App\Domain\Compliance\Enum\AdvisoryRiskProfile;
use App\Domain\Suitability\Service\AdvisoryProfileDivergenceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvisoryProfileDivergenceDetectorTest extends TestCase
{
    private AdvisoryProfileDivergenceDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AdvisoryProfileDivergenceDetector();
    }

    /**
     * @return iterable<string, array{AdvisoryRiskProfile, int, bool}>
     */
    public static function cases(): iterable
    {
        yield 'exact match => no divergence' => [AdvisoryRiskProfile::EQUILIBRE, 4, false];
        yield 'one level off (adjacent) => no divergence' => [AdvisoryRiskProfile::PRUDENT, 1, false];
        yield 'one level off the other way => no divergence' => [AdvisoryRiskProfile::PRUDENT, 3, false];
        yield 'two levels off => divergence' => [AdvisoryRiskProfile::PRUDENT, 4, true];
        yield 'extreme opposite ends => divergence' => [AdvisoryRiskProfile::OFFENSIF, 1, true];
        yield 'non determine => never a divergence' => [AdvisoryRiskProfile::NON_DETERMINE, 7, false];
    }

    #[DataProvider('cases')]
    public function testDetectsASignificantGapBetweenTheTwoProfiles(AdvisoryRiskProfile $advisoryProfile, int $investorLevel, bool $expected): void
    {
        self::assertSame($expected, $this->detector->detect($advisoryProfile, $investorLevel));
    }
}
