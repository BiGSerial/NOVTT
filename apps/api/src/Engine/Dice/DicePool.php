<?php
declare(strict_types=1);

namespace Novtt\Engine\Dice;

use InvalidArgumentException;

final readonly class DicePool
{
    public function __construct(
        public int $count,
        public int $faces,
        public int $threshold,
        public int $successesPerDie = 1
    ) {
        if ($count < 1 || $count > 100 || $faces < 2 || $faces > 1000) {
            throw new InvalidArgumentException('Dice count or faces out of bounds.');
        }
        if ($threshold < 1 || $threshold > $faces) {
            throw new InvalidArgumentException('Threshold must be within die faces.');
        }
        if ($successesPerDie < 1 || $successesPerDie > 10) {
            throw new InvalidArgumentException('Success weight out of bounds.');
        }
    }
}
