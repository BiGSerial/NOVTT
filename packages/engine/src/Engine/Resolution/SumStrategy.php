<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use Novtt\Engine\Contracts\RandomSource;

final readonly class SumStrategy
{
    public const NAME = 'sum';

    public function __construct(private RandomSource $random)
    {
    }

    public function roll(SumRoll $roll): RollOutcome
    {
        $faces = [];
        $kept = [];
        $total = $roll->modifier;
        $groupTotals = [];
        $natural = null;
        foreach ($roll->groups as $g => $group) {
            $rolled = [];
            for ($i = 0; $i < $group->count; $i++) {
                $rolled[] = $this->random->integer(1, $group->faces);
            }
            $keep = array_fill(0, count($rolled), true);
            if ($group->keepMode !== null) {
                $order = array_keys($rolled);
                // Stable: ties keep the earlier die.
                usort($order, static fn (int $a, int $b): int => $group->keepMode === 'highest'
                    ? [$rolled[$b], $a] <=> [$rolled[$a], $b]
                    : [$rolled[$a], $a] <=> [$rolled[$b], $b]);
                $keep = array_fill(0, count($rolled), false);
                foreach (array_slice($order, 0, (int) $group->keep) as $index) {
                    $keep[$index] = true;
                }
            }
            $sum = 0;
            foreach ($rolled as $i => $face) {
                if ($keep[$i]) {
                    $sum += $face;
                    if ($g === 0 && $natural === null) {
                        $natural = $face;
                    }
                }
            }
            $groupTotals[] = $group->sign * $sum;
            $total += $group->sign * $sum;
            array_push($faces, ...$rolled);
            array_push($kept, ...$keep);
        }
        $flags = [];
        if ($roll->criticalAtLeast !== null && $natural !== null && $natural >= $roll->criticalAtLeast) {
            $flags[] = 'critical';
        }
        if ($roll->fumbleAtMost !== null && $natural !== null && $natural <= $roll->fumbleAtMost) {
            $flags[] = 'fumble';
        }

        return new RollOutcome(self::NAME, $faces, $kept, $total, $roll->target === null ? null : $total >= $roll->target, $flags, [
            'groups' => $groupTotals,
            'modifier' => $roll->modifier,
            'natural' => $natural,
            'target' => $roll->target,
        ]);
    }
}
