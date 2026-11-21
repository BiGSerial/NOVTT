<?php
declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use InvalidArgumentException;
use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DicePool;
use Novtt\Engine\Dice\DicePoolRoller;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DicePoolTest extends TestCase
{
    public function test_deterministic_roll_and_success_weight(): void
    {
        $source = new class implements RandomSource {
            private array $values = [3, 7, 10];
            public function integer(int $min, int $max): int
            {
                return array_shift($this->values);
            }
        };
        $result = (new DicePoolRoller($source))->roll(new DicePool(3, 10, 7, 2));
        self::assertSame([3, 7, 10], $result->dice);
        self::assertSame(4, $result->successes);
    }

    #[DataProvider('invalidPools')]
    public function test_invalid_pool_bounds(int $count, int $faces, int $threshold, int $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DicePool($count, $faces, $threshold, $weight);
    }

    public static function invalidPools(): iterable
    {
        yield 'zero dice' => [0, 10, 6, 1];
        yield 'over 100 dice' => [101, 10, 6, 1];
        yield 'one face' => [1, 1, 1, 1];
        yield 'over 1000 faces' => [1, 1001, 6, 1];
        yield 'threshold zero' => [1, 10, 0, 1];
        yield 'threshold above faces' => [1, 10, 11, 1];
        yield 'weight zero' => [1, 10, 6, 0];
        yield 'weight over 10' => [1, 10, 6, 11];
    }
}
