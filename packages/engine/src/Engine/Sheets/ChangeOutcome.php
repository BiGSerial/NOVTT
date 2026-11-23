<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

use Novtt\Engine\Validation\ValidationResult;

/** Result of evaluating a set of operations against an official sheet. Pure data, no side effects. */
final readonly class ChangeOutcome
{
    /**
     * @param array<string, mixed> $sheet candidate sheet including the engine ledger (/_ledger)
     * @param array<string, int> $balances remaining balance per budget after the change
     */
    public function __construct(
        public ValidationResult $result,
        public array $sheet,
        public array $balances,
    ) {
    }
}
