<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/** Which references a formula may use where it is declared (e.g. a budget amount may not read budgets). */
final readonly class FormulaContext
{
    /** @param list<string> $variables */
    public function __construct(
        public bool $pointers = true,
        public bool $derived = true,
        public bool $budgets = true,
        public array $variables = [],
    ) {
    }
}
