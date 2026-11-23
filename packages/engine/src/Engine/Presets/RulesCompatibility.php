<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use InvalidArgumentException;
use Novtt\Engine\Formula\FormulaChecker;
use Novtt\Engine\Formula\FormulaContext;
use Novtt\Engine\Formula\References;
use Novtt\Engine\Resolution\DiceExpression;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;

/**
 * Engine-compatibility checks for the parts added by novtt.rules/2 (derived values, effects,
 * constraints, creation steps and choices, progression tracks, resources, inventory, rolls and
 * formula costs). Every formula is statically checked, every reference must exist in the sheet
 * schema or among declared ids, and derived values plus effects must form an acyclic graph.
 */
final class RulesCompatibility
{
    /** @var list<ValidationIssue> */
    private array $issues = [];
    /** @var array<string, true> */
    private array $budgets = [];
    /** @var array<string, string> */
    private array $derived = [];

    private function __construct(private readonly SchemaNavigator $schema)
    {
    }

    /** @param array<string, mixed> $rules @return list<ValidationIssue> */
    public static function check(SchemaNavigator $schema, array $rules): array
    {
        $checker = new self($schema);
        $checker->run($rules);

        return $checker->issues;
    }

    /** @param array<string, mixed> $rules */
    private function run(array $rules): void
    {
        foreach ($rules['budgets'] ?? [] as $budget) {
            $this->budgets[$budget['id']] = true;
        }
        foreach ($rules['derived'] ?? [] as $i => $derived) {
            $at = "/rules/derived/{$i}";
            if (isset($this->derived[$derived['id']])) {
                $this->error('rules.duplicate_id', $at, ['id' => $derived['id']]);
            }
            $this->derived[$derived['id']] = (string) $derived['path'];
        }

        foreach ($rules['budgets'] ?? [] as $i => $budget) {
            if (isset($budget['amount'])) {
                $this->formula($budget['amount'], new FormulaContext(pointers: true, derived: false, budgets: false), "/rules/budgets/{$i}/amount");
            }
        }
        foreach ($rules['costs'] ?? [] as $i => $cost) {
            if (($cost['model']['type'] ?? null) === 'formula') {
                $this->formula($cost['model']['formula'], new FormulaContext(variables: ['current', 'new']), "/rules/costs/{$i}/model/formula");
            }
        }

        $graph = [];
        $paths = [];
        foreach ($rules['derived'] ?? [] as $i => $derived) {
            $at = "/rules/derived/{$i}";
            $pointer = $this->pointer((string) $derived['path'], $at);
            if ($pointer !== null) {
                if (!$this->schema->isScalar($pointer)) {
                    $this->error('rules.derived_path_not_scalar', $at, ['path' => $derived['path']]);
                }
                if (isset($paths[$pointer->toString()])) {
                    $this->error('rules.duplicate_derived_path', $at, ['path' => $derived['path']]);
                }
                $paths[$pointer->toString()] = $derived['id'];
            }
            $refs = $this->formula($derived['formula'], new FormulaContext(), $at . '/formula');
            $graph[$derived['id']] = $this->derivedDependencies($refs, $paths);
        }
        foreach ($rules['effects'] ?? [] as $i => $effect) {
            $at = "/rules/effects/{$i}";
            if (!isset($this->derived[$effect['target']])) {
                $this->error('rules.unknown_derived', $at, ['derived' => $effect['target']]);
                continue;
            }
            $collection = $effect['source']['collection'] ?? null;
            $itemFields = [];
            if ($collection !== null) {
                $collectionPointer = $this->pointer((string) $collection, $at . '/source/collection');
                if ($collectionPointer !== null && !$this->schema->isCollection($collectionPointer)) {
                    $this->error('rules.not_a_collection', $at . '/source/collection', ['path' => $collection]);
                }
            }
            $refs = [];
            foreach (['value', 'when'] as $part) {
                if (isset($effect[$part])) {
                    $refs[] = $this->formula($effect[$part], new FormulaContext(), "{$at}/{$part}", $collection !== null && $part === 'value');
                }
            }
            if (isset($effect['source']['where'])) {
                $refs[] = $this->formula($effect['source']['where'], new FormulaContext(), "{$at}/source/where", true);
            }
            foreach ($refs as $ref) {
                foreach (array_keys($ref->itemFields) as $field) {
                    $itemFields[$field] = true;
                }
                $graph[$effect['target']] = [...($graph[$effect['target']] ?? []), ...$this->derivedDependencies($ref, $paths)];
            }
            if ($collection !== null) {
                $this->itemFields((string) $collection, array_keys($itemFields), $at);
            }
        }
        $cycle = self::cycle($graph);
        if ($cycle !== null) {
            $this->error('rules.derived_cycle', '/rules/derived', ['cycle' => implode(' -> ', $cycle)]);
        }

        foreach ($rules['constraints'] ?? [] as $i => $constraint) {
            $at = "/rules/constraints/{$i}";
            $this->formula($constraint['assert'], new FormulaContext(), $at . '/assert');
            foreach ($constraint['paths'] as $p => $path) {
                $this->pointer((string) $path, "{$at}/paths/{$p}");
            }
        }

        $this->creation((array) ($rules['creation'] ?? []));

        foreach ($rules['progression']['tracks'] ?? [] as $i => $track) {
            $at = "/rules/progression/tracks/{$i}";
            $pointer = $this->pointer((string) $track['path'], $at . '/path');
            if ($pointer !== null && !$this->schema->isInteger($pointer)) {
                $this->error('rules.target_not_integer', $at . '/path', ['target' => $track['path']]);
            }
            $this->formula($track['measure'], new FormulaContext(), $at . '/measure');
            $previous = null;
            foreach ($track['thresholds'] as $t => $threshold) {
                if ($previous !== null && $threshold <= $previous) {
                    $this->error('rules.thresholds_not_ascending', "{$at}/thresholds/{$t}");
                }
                $previous = $threshold;
            }
        }

        foreach ($rules['resources'] ?? [] as $i => $resource) {
            $at = "/rules/resources/{$i}";
            $pointer = $this->pointer((string) $resource['path'], $at . '/path');
            if ($pointer !== null && !$this->schema->isInteger($pointer)) {
                $this->error('rules.target_not_integer', $at . '/path', ['target' => $resource['path']]);
            }
            $this->formula($resource['max'], new FormulaContext(), $at . '/max');
        }

        foreach ($rules['inventory'] ?? [] as $i => $inventory) {
            $at = "/rules/inventory/{$i}";
            $pointer = $this->pointer((string) $inventory['collection'], $at . '/collection');
            if ($pointer === null) {
                continue;
            }
            if (!$this->schema->isCollection($pointer)) {
                $this->error('rules.not_a_collection', $at . '/collection', ['path' => $inventory['collection']]);
                continue;
            }
            $this->itemFields((string) $inventory['collection'], array_values(array_filter([
                $inventory['weight_field'] ?? null, $inventory['quantity_field'] ?? null, $inventory['equipped_field'] ?? null,
            ])), $at);
            if (isset($inventory['capacity'])) {
                $this->formula($inventory['capacity'], new FormulaContext(), $at . '/capacity');
            }
        }

        $this->rolls((array) ($rules['rolls'] ?? []));
    }

    /** @param array<string, mixed> $creation */
    private function creation(array $creation): void
    {
        $ids = [];
        foreach ($creation['steps'] ?? [] as $i => $step) {
            $at = "/rules/creation/steps/{$i}";
            if (isset($ids[$step['id']])) {
                $this->error('rules.duplicate_id', $at, ['id' => $step['id']]);
            }
            $ids[$step['id']] = true;
            foreach ($step['paths'] ?? [] as $p => $target) {
                if ($this->schema->expand((string) $target) === []) {
                    $this->error('rules.unknown_target', "{$at}/paths/{$p}", ['target' => $target]);
                }
            }
            foreach ($step['required'] ?? [] as $r => $path) {
                $this->pointer((string) $path, "{$at}/required/{$r}");
            }
            if (isset($step['complete_when'])) {
                $this->formula($step['complete_when'], new FormulaContext(), $at . '/complete_when');
            }
        }
        $choicePaths = [];
        foreach ($creation['choices'] ?? [] as $i => $choice) {
            $at = "/rules/creation/choices/{$i}";
            $pointer = $this->pointer((string) $choice['path'], $at . '/path');
            if ($pointer === null) {
                continue;
            }
            $node = $this->schema->at($pointer) ?? [];
            if (!$this->schema->isScalar($pointer)) {
                $this->error('rules.choice_path_not_scalar', $at . '/path', ['path' => $choice['path']]);
            }
            if (isset($choicePaths[$pointer->toString()])) {
                $this->error('rules.duplicate_choice_path', $at . '/path', ['path' => $choice['path']]);
            }
            $choicePaths[$pointer->toString()] = true;
            $values = [];
            foreach ($choice['options'] as $o => $option) {
                $value = $option['value'];
                $key = json_encode($value);
                if (isset($values[$key])) {
                    $this->error('rules.duplicate_option', "{$at}/options/{$o}", ['value' => is_scalar($value) ? (string) $value : null]);
                }
                $values[$key] = true;
                if (isset($node['enum']) && !in_array($value, (array) $node['enum'], true)) {
                    $this->error('rules.option_not_in_enum', "{$at}/options/{$o}", ['value' => is_scalar($value) ? (string) $value : null]);
                }
                if (isset($option['requires'])) {
                    $this->formula($option['requires'], new FormulaContext(), "{$at}/options/{$o}/requires");
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $rolls */
    private function rolls(array $rolls): void
    {
        $ids = [];
        foreach ($rolls as $i => $roll) {
            $at = "/rules/rolls/{$i}";
            if (isset($ids[$roll['id']])) {
                $this->error('rules.duplicate_id', $at, ['id' => $roll['id']]);
            }
            $ids[$roll['id']] = true;
            $inputs = array_keys((array) ($roll['inputs'] ?? []));
            foreach ((array) ($roll['inputs'] ?? []) as $name => $input) {
                foreach ($input['targets'] as $t => $target) {
                    $expanded = $this->schema->expand((string) $target);
                    if ($expanded === []) {
                        $this->error('rules.unknown_target', "{$at}/inputs/{$name}/targets/{$t}", ['target' => $target]);
                    }
                    foreach ($expanded as $pointer) {
                        if (!$this->schema->isInteger($pointer)) {
                            $this->error('rules.target_not_integer', "{$at}/inputs/{$name}/targets/{$t}", ['target' => $pointer->toString()]);
                        }
                    }
                }
            }
            $context = new FormulaContext(variables: array_map('strval', $inputs));
            if ($roll['strategy'] === 'sum') {
                try {
                    DiceExpression::parse((string) $roll['dice']);
                } catch (InvalidArgumentException $e) {
                    $this->error($e->getMessage(), $at . '/dice');
                }
                if (isset($roll['modifier'])) {
                    $this->formula($roll['modifier'], $context, $at . '/modifier');
                }
                $this->bounds($roll['target'] ?? null, $at . '/target', -100_000, 100_000);
            } else {
                $this->formula($roll['count'], $context, $at . '/count');
                $faces = (int) $roll['faces'];
                $this->bounds($roll['difficulty'], $at . '/difficulty', 1, $faces);
                $this->bounds($roll['required'] ?? null, $at . '/required', 0, 200);
                foreach (['cancel_at_most', 'bonus_at_least', 'explode_at_least', 'botch_at_most'] as $face) {
                    if (isset($roll[$face]) && ((int) $roll[$face] < 1 || (int) $roll[$face] > $faces)) {
                        $this->error('rules.face_out_of_bounds', "{$at}/{$face}", ['faces' => $faces]);
                    }
                }
                if (isset($roll['explode_at_least']) && (int) $roll['explode_at_least'] <= 1) {
                    $this->error('rules.explosion_unbounded', $at . '/explode_at_least');
                }
            }
        }
    }

    /** @param array<string, mixed>|null $bounds */
    private function bounds(?array $bounds, string $at, int $floor, int $ceiling): void
    {
        if ($bounds === null) {
            return;
        }
        $min = (int) $bounds['min'];
        $max = (int) $bounds['max'];
        $default = (int) ($bounds['default'] ?? $min);
        if ($min < $floor || $max > $ceiling || $min > $max || $default < $min || $default > $max) {
            $this->error('rules.invalid_bounds', $at, ['min' => $floor, 'max' => $ceiling]);
        }
    }

    private function formula(mixed $formula, FormulaContext $context, string $at, bool $itemScope = false): References
    {
        // A formula evaluated per item (effect value/where) may use `item` at its root.
        [$issues, $refs] = FormulaChecker::check($formula, $context, $at, $itemScope);
        array_push($this->issues, ...$issues);
        foreach (array_keys($refs->pointers) as $pointer) {
            $parsed = $this->pointer($pointer, $at);
            if ($parsed !== null && !$this->schema->isScalar($parsed)) {
                $this->error('rules.reference_not_scalar', $at, ['ref' => $pointer]);
            }
        }
        foreach (array_keys($refs->collections) as $collection) {
            $parsed = $this->pointer($collection, $at);
            if ($parsed !== null && !$this->schema->isCollection($parsed)) {
                $this->error('rules.not_a_collection', $at, ['path' => $collection]);
            }
            $this->itemFields($collection, array_keys($refs->itemFields), $at, lenient: true);
        }
        foreach (array_keys($refs->derived) as $id) {
            if (!isset($this->derived[$id])) {
                $this->error('rules.unknown_derived', $at, ['derived' => $id]);
            }
        }
        foreach (array_keys($refs->budgets) as $id) {
            if (!isset($this->budgets[$id])) {
                $this->error('rules.unknown_budget', $at, ['budget' => $id]);
            }
        }

        return $refs;
    }

    /** @param list<string> $fields */
    private function itemFields(string $collection, array $fields, string $at, bool $lenient = false): void
    {
        try {
            $pointer = JsonPointer::parse($collection);
        } catch (InvalidArgumentException) {
            return;
        }
        if (!$this->schema->isCollection($pointer)) {
            return;
        }
        foreach ($fields as $field) {
            if ($field !== 'id' && $this->schema->itemField($pointer, $field) === null) {
                // In formulas with several collections a field may belong to another one.
                if ($lenient && count($fields) > 0) {
                    continue;
                }
                $this->error('rules.unknown_item_field', $at, ['field' => $field]);
            }
        }
    }

    /**
     * Derived ids a formula depends on: explicit `derived` refs and pointers that address a
     * derived path.
     *
     * @param array<string, string> $paths derived path => id
     * @return list<string>
     */
    private function derivedDependencies(References $refs, array $paths): array
    {
        $deps = array_keys($refs->derived);
        foreach (array_keys($refs->pointers) as $pointer) {
            foreach ($this->derived as $id => $path) {
                if ($path === $pointer) {
                    $deps[] = $id;
                }
            }
        }

        return array_values(array_unique(array_map('strval', $deps)));
    }

    private function pointer(string $path, string $at): ?JsonPointer
    {
        try {
            $pointer = JsonPointer::parse($path);
        } catch (InvalidArgumentException) {
            $this->error('rules.invalid_pointer', $at, ['path' => $path]);

            return null;
        }
        if ($this->schema->at($pointer) === null) {
            $this->error('rules.unknown_target', $at, ['target' => $path]);

            return null;
        }

        return $pointer;
    }

    /**
     * @param array<string, list<string>> $graph
     * @return list<string>|null
     */
    public static function cycle(array $graph): ?array
    {
        $state = [];
        $stack = [];
        $visit = function (string $node) use (&$visit, &$state, &$stack, $graph): ?array {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    return [...array_slice($stack, (int) array_search($next, $stack, true)), $next];
                }
                if (($state[$next] ?? 0) === 0 && ($found = $visit($next)) !== null) {
                    return $found;
                }
            }
            array_pop($stack);
            $state[$node] = 2;

            return null;
        };
        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0 && ($found = $visit((string) $node)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, scalar|null> $params */
    private function error(string $rule, string $at, array $params = []): void
    {
        $this->issues[] = new ValidationIssue($rule, Severity::Error, [], [$at], $params);
    }
}
