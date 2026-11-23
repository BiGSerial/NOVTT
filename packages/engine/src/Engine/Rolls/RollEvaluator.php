<?php
declare(strict_types=1);

namespace Novtt\Engine\Rolls;

use InvalidArgumentException;

/**
 * Reads server-generated faces according to the request's reading. Pure and deterministic:
 * the same faces and parameters always give the same evaluation.
 */
final class RollEvaluator
{
    /**
     * @param list<int> $faces values already rolled by the server
     * @param int $bonus added to SUM / HIGHEST (fixed pools such as 1d20 + attribute + skill)
     * @param int|null $target minimum total (SUM / HIGHEST) or successes (SUCCESSES) to succeed
     */
    public static function evaluate(
        array $faces,
        RollReading $reading,
        int $bonus = 0,
        int $threshold = 1,
        int $successesPerDie = 1,
        ?int $target = null,
    ): RollEvaluation {
        if ($faces === []) {
            throw new InvalidArgumentException('A roll needs at least one die.');
        }
        $successes = 0;
        foreach ($faces as $face) {
            if (!is_int($face) || $face < 1) {
                throw new InvalidArgumentException('Invalid face value.');
            }
            if ($face >= $threshold) {
                $successes += $successesPerDie;
            }
        }
        $total = match ($reading) {
            RollReading::SUM => array_sum($faces) + $bonus,
            RollReading::HIGHEST => max($faces) + $bonus,
            RollReading::SUCCESSES => $successes,
        };

        return new RollEvaluation($total, $successes, $target === null ? null : $total >= $target);
    }
}
