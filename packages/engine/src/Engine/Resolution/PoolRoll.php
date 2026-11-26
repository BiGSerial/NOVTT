<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;
use Novtt\Engine\Dice\DiceLimits;

/**
 * Success-counting pool: each die at or above `difficulty` is a success. Optional rules, all
 * parameters of the preset: dice at or below `cancelAtMost` cancel one success each; dice at or
 * above `bonusAtLeast` add `bonusSuccesses`; dice at or above `explodeAtLeast` roll one more die
 * (bounded by DiceLimits::MAX_DICE); a botch is flagged when no die succeeded and at least one
 * die is at or below `botchAtMost`.
 */
final readonly class PoolRoll
{
    public function __construct(
        public int $count,
        public int $faces,
        public int $difficulty,
        public int $required = 1,
        public ?int $cancelAtMost = null,
        public ?int $bonusAtLeast = null,
        public int $bonusSuccesses = 0,
        public ?int $explodeAtLeast = null,
        public ?int $botchAtMost = null,
    ) {
        if ($count < 0 || $count > DiceLimits::MAX_DICE || $faces < 2 || $faces > DiceLimits::MAX_FACES) {
            throw new InvalidArgumentException('roll.dice_out_of_bounds');
        }
        if ($difficulty < 1 || $difficulty > $faces || $required < 0 || $required > DiceLimits::MAX_DICE * 2) {
            throw new InvalidArgumentException('roll.difficulty_out_of_bounds');
        }
        foreach ([$cancelAtMost, $bonusAtLeast, $explodeAtLeast, $botchAtMost] as $face) {
            if ($face !== null && ($face < 1 || $face > $faces)) {
                throw new InvalidArgumentException('roll.face_out_of_bounds');
            }
        }
        if ($explodeAtLeast !== null && $explodeAtLeast <= 1) {
            throw new InvalidArgumentException('roll.explosion_unbounded');
        }
        if ($bonusSuccesses < 0 || $bonusSuccesses > DiceLimits::MAX_SUCCESS_WEIGHT) {
            throw new InvalidArgumentException('roll.bonus_out_of_bounds');
        }
    }
}
