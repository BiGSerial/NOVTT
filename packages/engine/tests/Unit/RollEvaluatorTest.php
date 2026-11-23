<?php
declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use InvalidArgumentException;
use Novtt\Engine\Rolls\RollEvaluator;
use Novtt\Engine\Rolls\RollReading;
use PHPUnit\Framework\TestCase;

final class RollEvaluatorTest extends TestCase
{
    public function testSuccessesCountFacesAtOrAboveThreshold(): void
    {
        $e = RollEvaluator::evaluate([6, 2, 10, 5, 9], RollReading::SUCCESSES, threshold: 6, target: 3);
        self::assertSame(3, $e->successes);
        self::assertSame(3, $e->total);
        self::assertTrue($e->succeeded);
    }

    public function testWeightedSuccessesAndFailure(): void
    {
        $e = RollEvaluator::evaluate([10, 3], RollReading::SUCCESSES, threshold: 8, successesPerDie: 2, target: 3);
        self::assertSame(2, $e->total);
        self::assertFalse($e->succeeded);
    }

    public function testSumAddsBonusAndComparesTarget(): void
    {
        $e = RollEvaluator::evaluate([14], RollReading::SUM, bonus: 4, target: 18);
        self::assertSame(18, $e->total);
        self::assertTrue($e->succeeded);
    }

    public function testHighestKeepsBestFace(): void
    {
        $e = RollEvaluator::evaluate([3, 11, 7], RollReading::HIGHEST, bonus: 1);
        self::assertSame(12, $e->total);
        self::assertNull($e->succeeded);
    }

    public function testRejectsEmptyOrInvalidFaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RollEvaluator::evaluate([], RollReading::SUM);
    }

    public function testRejectsZeroFace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RollEvaluator::evaluate([0], RollReading::SUM);
    }
}
