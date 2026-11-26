<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/** Bounds of the declarative formula language (ADR 0012). Data, not configuration per game. */
final class FormulaLimits
{
    public const MAX_DEPTH = 24;
    public const MAX_NODES = 256;
    /** Evaluation steps for one whole sheet evaluation (all formulas together). */
    public const MAX_STEPS = 50_000;
    public const MAX_ABS_VALUE = 1_000_000_000;
    public const MAX_TABLE_ENTRIES = 128;
    public const MAX_STRING = 200;

    public const OPERATORS = [
        'add', 'subtract', 'multiply', 'divide', 'floor', 'min', 'max',
        'compare', 'and', 'or', 'not', 'if', 'lookup', 'sum', 'count',
    ];
    public const COMPARATORS = ['eq', 'ne', 'lt', 'le', 'gt', 'ge'];
    public const BUDGET_MEASURES = ['balance', 'granted', 'spent', 'amount'];

    private function __construct()
    {
    }
}
