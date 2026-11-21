<?php
declare(strict_types=1);

namespace Novtt\Engine\Dice;

use Novtt\Engine\Contracts\RandomSource;

final readonly class DicePoolRoller
{
    public function __construct(private RandomSource $random)
    {
    }

    public function roll(DicePool $pool): RollResult
    {
        $dice = [];
        $successes = 0;

        for ($i = 0; $i < $pool->count; $i++) {
            $value = $this->random->integer(1, $pool->faces);
            $dice[] = $value;
            if ($value >= $pool->threshold) {
                $successes += $pool->successesPerDie;
            }
        }

        return new RollResult($dice, $successes);
    }
}
