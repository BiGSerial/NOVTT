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
        if ($count < 1 || $count > DiceLimits::MAX_DICE || $faces < 2 || $faces > DiceLimits::MAX_FACES) {
            throw new InvalidArgumentException('Dice count or faces out of bounds.');
        }
        if ($threshold < 1 || $threshold > $faces) {
            throw new InvalidArgumentException('Threshold must be within die faces.');
        }
        if ($successesPerDie < 1 || $successesPerDie > DiceLimits::MAX_SUCCESS_WEIGHT) {
            throw new InvalidArgumentException('Success weight out of bounds.');
        }
    }
}
