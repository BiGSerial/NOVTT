<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;
use Novtt\Engine\Dice\DiceLimits;

/**
 * Sum resolution: dice groups plus a flat modifier, compared to an optional target. A natural
 * critical/fumble is read on the kept dice of the first group (e.g. one d20).
 */
final readonly class SumRoll
{
    /** @param list<DiceGroup> $groups */
    public function __construct(
        public array $groups,
        public int $modifier = 0,
        public ?int $target = null,
        public ?int $criticalAtLeast = null,
        public ?int $fumbleAtMost = null,
    ) {
        if ($groups === [] || count($groups) > 10) {
            throw new InvalidArgumentException('roll.invalid_groups');
        }
        if (array_sum(array_map(static fn (DiceGroup $g): int => $g->count, $groups)) > DiceLimits::MAX_DICE) {
            throw new InvalidArgumentException('roll.dice_out_of_bounds');
        }
        if (abs($modifier) > 10_000 || ($target !== null && abs($target) > 100_000)) {
            throw new InvalidArgumentException('roll.modifier_out_of_bounds');
        }
    }

    /** Same roll with one more die on the first group, keeping the best (or worst) original count. */
    public function withEdge(string $keepMode, int $extraDice = 1): self
    {
        $first = $this->groups[0];
        $groups = $this->groups;
        $groups[0] = new DiceGroup($first->count + $extraDice, $first->faces, $first->sign, $keepMode, $first->keep ?? $first->count);

        return new self($groups, $this->modifier, $this->target, $this->criticalAtLeast, $this->fumbleAtMost);
    }
}
