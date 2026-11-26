<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;
use Novtt\Engine\Dice\DiceLimits;

/** `count`d`faces`, optionally keeping only the highest or lowest `keep` dice, added or subtracted. */
final readonly class DiceGroup
{
    public function __construct(
        public int $count,
        public int $faces,
        public int $sign = 1,
        public ?string $keepMode = null,
        public ?int $keep = null,
    ) {
        if ($count < 1 || $count > DiceLimits::MAX_DICE || $faces < 2 || $faces > DiceLimits::MAX_FACES) {
            throw new InvalidArgumentException('roll.dice_out_of_bounds');
        }
        if ($sign !== 1 && $sign !== -1) {
            throw new InvalidArgumentException('roll.invalid_sign');
        }
        if ($keepMode !== null && (!in_array($keepMode, ['highest', 'lowest'], true) || $keep === null || $keep < 1 || $keep > $count)) {
            throw new InvalidArgumentException('roll.invalid_keep');
        }
    }
}
