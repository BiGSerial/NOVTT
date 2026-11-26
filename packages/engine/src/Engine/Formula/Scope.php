<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/**
 * Values a formula may read. Implementations decide what each reference means for the sheet
 * being evaluated; the evaluator itself never touches storage, files, network or the clock.
 */
interface Scope
{
    /** Value at a sheet JSON Pointer, falling back to the schema default when absent. */
    public function pointer(string $pointer): mixed;

    /** A derived value by id (already including effects). */
    public function derived(string $id): mixed;

    /** @param string $measure one of FormulaLimits::BUDGET_MEASURES */
    public function budget(string $id, string $measure): int;

    /** A context variable (e.g. `current`, `new`, a roll input). */
    public function variable(string $name): mixed;

    /** @return list<array<string, mixed>> items of a collection */
    public function collection(string $pointer): array;

    /** Step budget shared by all formulas evaluated through this scope. */
    public function meter(): StepMeter;
}
