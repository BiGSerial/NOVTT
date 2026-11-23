<?php

declare(strict_types=1);

namespace Novtt\Engine\Formula;

/**
 * Deterministic interpreter of a checked formula AST. Pure: reads only through Scope, never
 * calls user code, and stops after the scope's shared step budget (StepMeter) so a hostile
 * preset cannot make one request expensive. Values are int, float, bool, string or null; numbers
 * outside ±FormulaLimits::MAX_ABS_VALUE are rejected rather than wrapped.
 */
final class FormulaEvaluator
{
    /** @var list<array<string, mixed>> */
    private array $items = [];

    public function __construct(private readonly Scope $scope)
    {
    }

    /** Evaluates with `item` nodes bound to one collection item. @param array<string, mixed> $item */
    public function evaluateWithItem(mixed $node, array $item): int|float|bool|string|null
    {
        $this->items[] = $item;
        try {
            return $this->evaluate($node);
        } finally {
            array_pop($this->items);
        }
    }

    public function evaluate(mixed $node): int|float|bool|string|null
    {
        $this->scope->meter()->tick();
        if (!is_array($node)) {
            throw new FormulaException('formula.invalid_node');
        }
        if (array_key_exists('const', $node)) {
            return $node['const'];
        }
        if (array_key_exists('ref', $node)) {
            return self::scalar($this->scope->pointer((string) $node['ref']));
        }
        if (array_key_exists('derived', $node)) {
            return self::scalar($this->scope->derived((string) $node['derived']));
        }
        if (array_key_exists('budget', $node)) {
            return $this->scope->budget((string) $node['budget'], (string) $node['of']);
        }
        if (array_key_exists('var', $node)) {
            return self::scalar($this->scope->variable((string) $node['var']));
        }
        if (array_key_exists('item', $node)) {
            $item = $this->items[count($this->items) - 1] ?? throw new FormulaException('formula.item_outside_collection');

            return self::scalar($item[(string) $node['item']] ?? null);
        }

        $op = (string) ($node['op'] ?? '');
        $args = (array) ($node['args'] ?? []);

        return match ($op) {
            'add' => $this->bounded(array_sum($this->numbers($args))),
            'subtract' => $this->bounded($this->fold($args, static fn (int|float $a, int|float $b): int|float => $a - $b)),
            'multiply' => $this->bounded($this->fold($args, static fn (int|float $a, int|float $b): int|float => $a * $b)),
            'divide' => $this->divide($args),
            'floor' => (int) floor($this->number($args[0] ?? null)),
            'min' => min($this->numbers($args)),
            'max' => max($this->numbers($args)),
            'and' => $this->all($args),
            'or' => $this->any($args),
            'not' => !$this->boolean($args[0] ?? null),
            'compare' => $this->compare((string) ($node['cmp'] ?? ''), $args),
            'if' => $this->boolean($args[0] ?? null) ? $this->evaluate($args[1] ?? null) : $this->evaluate($args[2] ?? null),
            'lookup' => $this->lookup($node),
            'sum' => $this->sum($node),
            'count' => $this->count($node),
            default => throw new FormulaException('UNSUPPORTED_CAPABILITY', ['operator' => $op]),
        };
    }

    /** @param list<mixed> $args @return list<int|float> */
    private function numbers(array $args): array
    {
        return array_map(fn (mixed $arg): int|float => $this->number($arg), $args);
    }

    private function number(mixed $node): int|float
    {
        $value = $this->evaluate($node);
        if (!is_int($value) && !is_float($value)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'number']);
        }

        return $value;
    }

    private function boolean(mixed $node): bool
    {
        $value = $this->evaluate($node);
        if (!is_bool($value)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'boolean']);
        }

        return $value;
    }

    /** @param list<mixed> $args @param callable(int|float, int|float): (int|float) $step */
    private function fold(array $args, callable $step): int|float
    {
        $values = $this->numbers($args);
        $acc = array_shift($values);
        foreach ($values as $value) {
            $acc = $this->bounded($step($acc, $value));
        }

        return $acc;
    }

    /** @param list<mixed> $args */
    private function divide(array $args): int|float
    {
        $a = $this->number($args[0] ?? null);
        $b = $this->number($args[1] ?? null);
        if ($b == 0) {
            throw new FormulaException('formula.division_by_zero');
        }
        if (is_int($a) && is_int($b) && $a % $b === 0) {
            return intdiv($a, $b);
        }

        return $this->bounded($a / $b);
    }

    /** @param list<mixed> $args */
    private function all(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$this->boolean($arg)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $args */
    private function any(array $args): bool
    {
        foreach ($args as $arg) {
            if ($this->boolean($arg)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $args */
    private function compare(string $cmp, array $args): bool
    {
        $a = $this->evaluate($args[0] ?? null);
        $b = $this->evaluate($args[1] ?? null);
        $numeric = (is_int($a) || is_float($a)) && (is_int($b) || is_float($b));
        if (!$numeric && !in_array($cmp, ['eq', 'ne'], true)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'number']);
        }
        // Strict equality across types: "1" is not 1, true is not 1.
        $equal = $numeric ? $a == $b : $a === $b;

        return match ($cmp) {
            'eq' => $equal,
            'ne' => !$equal,
            'lt' => $a < $b,
            'le' => $a <= $b,
            'gt' => $a > $b,
            'ge' => $a >= $b,
            default => throw new FormulaException('formula.invalid_comparator'),
        };
    }

    /** @param array<string, mixed> $node */
    private function lookup(array $node): int|float|bool|string|null
    {
        $key = $this->evaluate($node['key'] ?? null);
        if (isset($node['table'])) {
            $lookupKey = is_bool($key) ? ($key ? 'true' : 'false') : (string) $key;
            if (is_array($node['table']) && array_key_exists($lookupKey, $node['table'])) {
                return $node['table'][$lookupKey];
            }
        } else {
            if (!is_int($key) && !is_float($key)) {
                throw new FormulaException('formula.type_mismatch', ['expected' => 'number']);
            }
            $found = false;
            $result = null;
            foreach ((array) $node['steps'] as [$threshold, $value]) {
                if ($key >= $threshold) {
                    $found = true;
                    $result = $value;
                }
            }
            if ($found) {
                return $result;
            }
        }
        if (array_key_exists('default', $node)) {
            return $this->evaluate($node['default']);
        }
        throw new FormulaException('formula.lookup_missing');
    }

    /** @param array<string, mixed> $node */
    private function sum(array $node): int|float
    {
        $total = 0;
        foreach ($this->matching($node) as $item) {
            $this->items[] = $item;
            try {
                $total = $this->bounded($total + $this->number($node['value'] ?? null));
            } finally {
                array_pop($this->items);
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $node */
    private function count(array $node): int
    {
        return count($this->matching($node));
    }

    /** @param array<string, mixed> $node @return list<array<string, mixed>> */
    private function matching(array $node): array
    {
        $out = [];
        foreach ($this->scope->collection((string) $node['collection']) as $item) {
            $this->scope->meter()->tick();
            if (array_key_exists('where', $node)) {
                $this->items[] = $item;
                try {
                    $keep = $this->boolean($node['where']);
                } finally {
                    array_pop($this->items);
                }
                if (!$keep) {
                    continue;
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    private function bounded(int|float $value): int|float
    {
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new FormulaException('formula.out_of_bounds');
        }
        if (abs($value) > FormulaLimits::MAX_ABS_VALUE) {
            throw new FormulaException('formula.out_of_bounds');
        }

        return $value;
    }

    private static function scalar(mixed $value): int|float|bool|string|null
    {
        if (is_int($value) || is_float($value) || is_bool($value) || is_string($value) || $value === null) {
            return $value;
        }
        throw new FormulaException('formula.type_mismatch', ['expected' => 'scalar']);
    }
}
