<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

use InvalidArgumentException;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;

/**
 * Static check of one formula AST before a preset is accepted: only allow-listed node shapes and
 * operators, bounded size and depth, references allowed in the formula's context. Collects every
 * reference so callers can check that targets exist and build the dependency graph.
 *
 * Node shapes (JSON):
 *   {"const": scalar}
 *   {"ref": "/json/pointer"}                         sheet value
 *   {"derived": "id"}                                derived value
 *   {"budget": "id", "of": "balance|granted|spent|amount"}
 *   {"var": "name"}                                  context variable
 *   {"item": "field"}                                field of the current item (inside sum/count)
 *   {"op": "add|subtract|multiply|min|max|and|or", "args": [n, ...]}
 *   {"op": "divide", "args": [a, b]}  {"op": "floor|not", "args": [a]}
 *   {"op": "compare", "cmp": "eq|ne|lt|le|gt|ge", "args": [a, b]}
 *   {"op": "if", "args": [condition, then, else]}
 *   {"op": "lookup", "key": n, "table": {"k": scalar}, "default": n?}
 *   {"op": "lookup", "key": n, "steps": [[threshold, scalar], ...], "default": n?}
 *   {"op": "sum", "collection": "/ptr", "value": n, "where": n?}
 *   {"op": "count", "collection": "/ptr", "where": n?}
 */
final class FormulaChecker
{
    /** @var list<ValidationIssue> */
    private array $issues = [];
    private int $nodes = 0;
    private References $refs;

    private function __construct(private readonly FormulaContext $context)
    {
        $this->refs = new References();
    }

    /**
     * @param bool $itemScope the formula is evaluated once per collection item, so `item` is allowed at its root
     * @return array{0: list<ValidationIssue>, 1: References}
     */
    public static function check(mixed $node, FormulaContext $context, string $at, bool $itemScope = false): array
    {
        $checker = new self($context);
        $checker->visit($node, $at, 0, $itemScope);
        if ($checker->nodes > FormulaLimits::MAX_NODES) {
            $checker->issues[] = self::issue('formula.too_complex', $at, ['max_nodes' => FormulaLimits::MAX_NODES]);
        }

        return [$checker->issues, $checker->refs];
    }

    private function visit(mixed $node, string $at, int $depth, bool $inItem): void
    {
        if (++$this->nodes > FormulaLimits::MAX_NODES) {
            return;
        }
        if ($depth > FormulaLimits::MAX_DEPTH) {
            $this->issues[] = self::issue('formula.too_deep', $at, ['max_depth' => FormulaLimits::MAX_DEPTH]);

            return;
        }
        if (!is_array($node) || array_is_list($node) || $node === []) {
            $this->issues[] = self::issue('formula.invalid_node', $at);

            return;
        }
        $keys = array_keys($node);
        sort($keys);
        if (array_key_exists('const', $node)) {
            $this->only($node, ['const'], $at);
            $value = $node['const'];
            if (!(is_int($value) || is_float($value) || is_bool($value) || is_string($value) || $value === null)) {
                $this->issues[] = self::issue('formula.invalid_const', $at);
            } elseif (is_string($value) && strlen($value) > FormulaLimits::MAX_STRING) {
                $this->issues[] = self::issue('formula.string_too_long', $at);
            } elseif ((is_int($value) || is_float($value)) && abs($value) > FormulaLimits::MAX_ABS_VALUE) {
                $this->issues[] = self::issue('formula.out_of_bounds', $at);
            }

            return;
        }
        if (array_key_exists('ref', $node)) {
            $this->only($node, ['ref'], $at);
            if (!$this->context->pointers) {
                $this->issues[] = self::issue('formula.reference_not_allowed', $at, ['kind' => 'ref']);
            } elseif ($this->pointer($node['ref'], $at)) {
                $this->refs->pointers[(string) $node['ref']] = true;
            }

            return;
        }
        if (array_key_exists('derived', $node)) {
            $this->only($node, ['derived'], $at);
            if (!$this->context->derived) {
                $this->issues[] = self::issue('formula.reference_not_allowed', $at, ['kind' => 'derived']);
            } elseif ($this->identifier($node['derived'], $at)) {
                $this->refs->derived[(string) $node['derived']] = true;
            }

            return;
        }
        if (array_key_exists('budget', $node)) {
            $this->only($node, ['budget', 'of'], $at);
            if (!$this->context->budgets) {
                $this->issues[] = self::issue('formula.reference_not_allowed', $at, ['kind' => 'budget']);
            } elseif ($this->identifier($node['budget'], $at)) {
                if (!in_array($node['of'] ?? null, FormulaLimits::BUDGET_MEASURES, true)) {
                    $this->issues[] = self::issue('formula.invalid_budget_measure', $at);
                }
                $this->refs->budgets[(string) $node['budget']] = true;
            }

            return;
        }
        if (array_key_exists('var', $node)) {
            $this->only($node, ['var'], $at);
            if (!is_string($node['var']) || !in_array($node['var'], $this->context->variables, true)) {
                $this->issues[] = self::issue('formula.unknown_variable', $at, ['var' => is_string($node['var']) ? $node['var'] : null]);
            } else {
                $this->refs->variables[$node['var']] = true;
            }

            return;
        }
        if (array_key_exists('item', $node)) {
            $this->only($node, ['item'], $at);
            if (!$inItem) {
                $this->issues[] = self::issue('formula.item_outside_collection', $at);
            } elseif ($this->identifier($node['item'], $at)) {
                $this->refs->itemFields[(string) $node['item']] = true;
            }

            return;
        }
        $op = $node['op'] ?? null;
        if (!is_string($op) || !in_array($op, FormulaLimits::OPERATORS, true)) {
            $this->issues[] = self::issue('UNSUPPORTED_CAPABILITY', $at, ['operator' => is_string($op) ? $op : null]);

            return;
        }
        match ($op) {
            'lookup' => $this->lookup($node, $at, $depth, $inItem),
            'sum', 'count' => $this->aggregate($op, $node, $at, $depth),
            default => $this->operator($op, $node, $at, $depth, $inItem),
        };
    }

    /** @param array<string, mixed> $node */
    private function operator(string $op, array $node, string $at, int $depth, bool $inItem): void
    {
        $this->only($node, $op === 'compare' ? ['op', 'cmp', 'args'] : ['op', 'args'], $at);
        $args = $node['args'] ?? null;
        if (!is_array($args) || !array_is_list($args)) {
            $this->issues[] = self::issue('formula.invalid_arguments', $at, ['op' => $op]);

            return;
        }
        [$min, $max] = match ($op) {
            'floor', 'not' => [1, 1],
            'divide', 'compare' => [2, 2],
            'if' => [3, 3],
            default => [2, 32],
        };
        if (count($args) < $min || count($args) > $max) {
            $this->issues[] = self::issue('formula.arity', $at, ['op' => $op, 'min' => $min, 'max' => $max]);
        }
        if ($op === 'compare' && !in_array($node['cmp'] ?? null, FormulaLimits::COMPARATORS, true)) {
            $this->issues[] = self::issue('formula.invalid_comparator', $at);
        }
        if ($op === 'divide' && isset($args[1]) && is_array($args[1]) && array_key_exists('const', $args[1]) && in_array($args[1]['const'], [0, 0.0], true)) {
            $this->issues[] = self::issue('formula.division_by_zero', $at . '/args/1');
        }
        foreach ($args as $i => $arg) {
            $this->visit($arg, "{$at}/args/{$i}", $depth + 1, $inItem);
        }
    }

    /** @param array<string, mixed> $node */
    private function lookup(array $node, string $at, int $depth, bool $inItem): void
    {
        $hasTable = array_key_exists('table', $node);
        $hasSteps = array_key_exists('steps', $node);
        $this->only($node, ['op', 'key', $hasSteps ? 'steps' : 'table', 'default'], $at);
        if ($hasTable === $hasSteps) {
            $this->issues[] = self::issue('formula.lookup_needs_table_or_steps', $at);

            return;
        }
        $this->visit($node['key'] ?? null, $at . '/key', $depth + 1, $inItem);
        if (array_key_exists('default', $node)) {
            $this->visit($node['default'], $at . '/default', $depth + 1, $inItem);
        }
        if ($hasTable) {
            $table = $node['table'];
            if (!is_array($table) || ($table !== [] && array_is_list($table)) || count($table) > FormulaLimits::MAX_TABLE_ENTRIES) {
                $this->issues[] = self::issue('formula.invalid_table', $at . '/table');

                return;
            }
            foreach ($table as $key => $value) {
                if (!is_scalar($value) && $value !== null) {
                    $this->issues[] = self::issue('formula.invalid_table', $at . '/table/' . $key);
                }
            }

            return;
        }
        $steps = $node['steps'];
        if (!is_array($steps) || !array_is_list($steps) || $steps === [] || count($steps) > FormulaLimits::MAX_TABLE_ENTRIES) {
            $this->issues[] = self::issue('formula.invalid_steps', $at . '/steps');

            return;
        }
        $previous = null;
        foreach ($steps as $i => $step) {
            if (!is_array($step) || !array_is_list($step) || count($step) !== 2 || !is_int($step[0]) || (!is_scalar($step[1]) && $step[1] !== null)) {
                $this->issues[] = self::issue('formula.invalid_steps', "{$at}/steps/{$i}");

                return;
            }
            if ($previous !== null && $step[0] <= $previous) {
                $this->issues[] = self::issue('formula.steps_not_ascending', "{$at}/steps/{$i}");
            }
            $previous = $step[0];
        }
    }

    /** @param array<string, mixed> $node */
    private function aggregate(string $op, array $node, string $at, int $depth): void
    {
        $this->only($node, $op === 'sum' ? ['op', 'collection', 'value', 'where'] : ['op', 'collection', 'where'], $at);
        if (!$this->context->pointers) {
            $this->issues[] = self::issue('formula.reference_not_allowed', $at, ['kind' => 'collection']);

            return;
        }
        if ($this->pointer($node['collection'] ?? null, $at . '/collection')) {
            $this->refs->collections[(string) $node['collection']] = true;
        }
        if ($op === 'sum') {
            $this->visit($node['value'] ?? null, $at . '/value', $depth + 1, true);
        }
        if (array_key_exists('where', $node)) {
            $this->visit($node['where'], $at . '/where', $depth + 1, true);
        }
    }

    /** @param array<string, mixed> $node @param list<string> $allowed */
    private function only(array $node, array $allowed, string $at): void
    {
        foreach (array_keys($node) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->issues[] = self::issue('formula.unknown_key', $at, ['key' => (string) $key]);
            }
        }
    }

    private function pointer(mixed $value, string $at): bool
    {
        if (!is_string($value)) {
            $this->issues[] = self::issue('formula.invalid_reference', $at);

            return false;
        }
        try {
            $pointer = JsonPointer::parse($value);
        } catch (InvalidArgumentException) {
            $this->issues[] = self::issue('formula.invalid_reference', $at);

            return false;
        }
        if (str_starts_with($pointer->segments[0], '_')) {
            $this->issues[] = self::issue('formula.reserved_reference', $at);

            return false;
        }

        return true;
    }

    private function identifier(mixed $value, string $at): bool
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
            $this->issues[] = self::issue('formula.invalid_reference', $at);

            return false;
        }

        return true;
    }

    /** @param array<string, scalar|null> $params */
    private static function issue(string $rule, string $at, array $params = []): ValidationIssue
    {
        return new ValidationIssue($rule, Severity::Error, [], [$at], $params);
    }
}
