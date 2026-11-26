<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/** Evaluation steps shared by every formula evaluated for one request (bounded cost). */
final class StepMeter
{
    private int $used = 0;

    public function __construct(public readonly int $max = FormulaLimits::MAX_STEPS)
    {
    }

    public function tick(int $steps = 1): void
    {
        $this->used += $steps;
        if ($this->used > $this->max) {
            throw new FormulaException('formula.evaluation_budget_exceeded', ['max_steps' => $this->max]);
        }
    }

    public function used(): int
    {
        return $this->used;
    }
}
