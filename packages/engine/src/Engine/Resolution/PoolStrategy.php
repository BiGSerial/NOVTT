<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DiceLimits;

final readonly class PoolStrategy
{
    public const NAME = 'pool';

    public function __construct(private RandomSource $random)
    {
    }

    public function roll(PoolRoll $roll): RollOutcome
    {
        $faces = [];
        $pending = $roll->count;
        while ($pending > 0 && count($faces) < DiceLimits::MAX_DICE) {
            $pending--;
            $face = $this->random->integer(1, $roll->faces);
            $faces[] = $face;
            if ($roll->explodeAtLeast !== null && $face >= $roll->explodeAtLeast) {
                $pending++;
            }
        }
        $successes = 0;
        $bonus = 0;
        $cancels = 0;
        $lows = 0;
        foreach ($faces as $face) {
            if ($face >= $roll->difficulty) {
                $successes++;
                if ($roll->bonusAtLeast !== null && $face >= $roll->bonusAtLeast) {
                    $bonus += $roll->bonusSuccesses;
                }
            }
            if ($roll->cancelAtMost !== null && $face <= $roll->cancelAtMost) {
                $cancels++;
            }
            if ($roll->botchAtMost !== null && $face <= $roll->botchAtMost) {
                $lows++;
            }
        }
        $net = max(0, $successes + $bonus - $cancels);
        $flags = [];
        if ($roll->botchAtMost !== null && $successes === 0 && $lows > 0) {
            $flags[] = 'botch';
        }

        return new RollOutcome(self::NAME, $faces, array_fill(0, count($faces), true), $net, $roll->count === 0 && $faces === [] ? false : $net >= $roll->required, $flags, [
            'difficulty' => $roll->difficulty,
            'successes' => $successes,
            'bonus' => $bonus,
            'cancelled' => min($cancels, $successes + $bonus),
            'required' => $roll->required,
            'exploded' => count($faces) - $roll->count,
        ]);
    }
}
