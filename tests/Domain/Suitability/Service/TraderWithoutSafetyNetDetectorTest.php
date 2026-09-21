<?php

declare(strict_types=1);

namespace App\Tests\Domain\Suitability\Service;

use App\Domain\Suitability\Service\TraderWithoutSafetyNetDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TraderWithoutSafetyNetDetectorTest extends TestCase
{
    private TraderWithoutSafetyNetDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TraderWithoutSafetyNetDetector();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'high tolerance + very low capacity => detected' => [['toleranceLevel' => 7, 'capacityLevel' => 1], true];
        yield 'threshold values exactly at the boundary => detected' => [['toleranceLevel' => 6, 'capacityLevel' => 2], true];
        yield 'high tolerance but comfortable capacity => not detected' => [['toleranceLevel' => 7, 'capacityLevel' => 4], false];
        yield 'low tolerance and low capacity => not detected (no appetite mismatch)' => [['toleranceLevel' => 2, 'capacityLevel' => 1], false];
        yield 'missing keys => not detected' => [[], false];
        yield 'non-integer values => not detected' => [['toleranceLevel' => '7', 'capacityLevel' => '1'], false];
    }

    #[DataProvider('cases')]
    public function testDetectsTheHighToleranceLowCapacityPattern(array $scoreSnapshot, bool $expected): void
    {
        self::assertSame($expected, $this->detector->detect($scoreSnapshot));
    }
}
