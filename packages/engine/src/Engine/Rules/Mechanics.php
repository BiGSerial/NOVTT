<?php

declare(strict_types=1);

namespace Novtt\Engine\Rules;

use InvalidArgumentException;
use Novtt\Engine\Formula\FormulaEvaluator;
use Novtt\Engine\Formula\FormulaException;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Presets\SheetProjector;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;

/**
 * Executes the declarative mechanics of one pinned rules document (novtt.rules/1 or /2) against a
 * sheet: budget amounts, derived values with effects and their decision trace, choices with
 * prerequisites, n-ary constraints, level/milestone tracks, resources, inventory capacity and
 * creation steps. No game-specific branches: every meaning comes from the preset.
 *
 * The rules document MUST already have passed PresetCompatibility; evaluation errors that the
 * static check cannot exclude (division by zero at runtime, budget exhaustion, type mismatch on
 * user data) are reported as validation issues, never thrown to the caller.
 */
final class Mechanics
{
    /** @var array<string, array<string, mixed>> */
    private array $derived = [];
    /** @var array<string, string> path => derived id */
    private array $derivedPaths = [];
    /** @var array<string, list<array<string, mixed>>> derived id => effects */
    private array $effectsByTarget = [];

    /** @param array<string, mixed> $rules */
    public function __construct(private readonly array $rules, private readonly SchemaNavigator $schema)
    {
        foreach ($rules['derived'] ?? [] as $derived) {
            $this->derived[$derived['id']] = $derived;
            $this->derivedPaths[JsonPointer::parse($derived['path'])->toString()] = $derived['id'];
        }
        foreach ($rules['effects'] ?? [] as $effect) {
            $this->effectsByTarget[$effect['target']][] = $effect;
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function derivedAt(string $pointer): ?string
    {
        return $this->derivedPaths[$pointer] ?? null;
    }

    public function isDerivedPath(JsonPointer $pointer): bool
    {
        return isset($this->derivedPaths[$pointer->toString()]);
    }

    /** Whether the preset locks sheets of characters while their campaign has a live session. */
    public function locksDuringSession(): bool
    {
        return (bool) ($this->rules['governance']['lock_during_session'] ?? false);
    }

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     * @param list<array<string, mixed>>|null $ledger defaults to the sheet's own ledger
     * @return array<string, array{amount: int, granted: int, spent: int}>
     */
    public function budgetView(array $sheet, array $grants, ?array $ledger = null): array
    {
        $plain = new SheetScope($sheet, $this->schema, null);
        $view = [];
        foreach ($this->rules['budgets'] ?? [] as $budget) {
            $amount = (int) ($budget['initial_amount'] ?? 0);
            if (isset($budget['amount'])) {
                try {
                    $value = (new FormulaEvaluator($plain))->evaluate($budget['amount']);
                    $amount = is_int($value) ? $value : (is_float($value) ? (int) floor($value) : 0);
                } catch (FormulaException) {
                    $amount = 0;
                }
            }
            $view[$budget['id']] = ['amount' => $amount, 'granted' => (int) ($grants[$budget['id']] ?? 0), 'spent' => 0];
        }
        foreach ($ledger ?? (array) ($sheet['_ledger'] ?? []) as $entry) {
            if (isset($view[$entry['budget']])) {
                $view[$entry['budget']]['spent'] += (int) $entry['cost'];
            }
        }

        return $view;
    }

    /** @param array<string, array{amount: int, granted: int, spent: int}> $view @return array<string, int> */
    public static function balancesOf(array $view): array
    {
        return array_map(static fn (array $b): int => $b['amount'] + $b['granted'] - $b['spent'], $view);
    }

    /** @param array<string, mixed> $sheet @param array<string, int> $grants */
    public function scope(array $sheet, array $grants, ?DecisionTrace $trace = null): SheetScope
    {
        return new SheetScope($sheet, $this->schema, $this, $this->budgetView($sheet, $grants), [], $trace);
    }

    /** Called by SheetScope; computes base formula, then effects (adds by stack group, then the winning set). */
    public function computeDerived(string $id, SheetScope $scope, ?DecisionTrace $trace): int|float|bool|string|null
    {
        $definition = $this->derived[$id] ?? throw new FormulaException('formula.unknown_derived', ['derived' => $id]);
        $base = (new FormulaEvaluator($scope))->evaluate($definition['formula']);
        $modifiers = [];
        $groups = [];
        $sets = [];
        foreach ($this->effectsByTarget[$id] ?? [] as $effect) {
            foreach ($this->effectInstances($effect, $scope) as [$value, $source]) {
                $entry = ['effect' => $effect['id'], 'mode' => $effect['mode'] ?? 'add', 'value' => $value, 'source' => $source, 'applied' => false];
                if (($effect['mode'] ?? 'add') === 'set') {
                    $sets[] = [(int) ($effect['priority'] ?? 0), count($modifiers)];
                } else {
                    $group = (string) ($effect['stack_group'] ?? $effect['id']);
                    $groups[$group][] = [count($modifiers), (string) ($effect['stacking'] ?? 'sum')];
                }
                $modifiers[] = $entry;
            }
        }
        $value = $base;
        if ($groups !== [] && !is_int($value) && !is_float($value)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'number', 'derived' => $id]);
        }
        foreach ($groups as $members) {
            $stacking = $members[0][1];
            if ($stacking === 'highest') {
                $best = null;
                foreach ($members as [$index]) {
                    if ($best === null || $modifiers[$index]['value'] > $modifiers[$best]['value']) {
                        $best = $index;
                    }
                }
                $value += $modifiers[$best]['value'];
                $modifiers[$best]['applied'] = true;
            } else {
                foreach ($members as [$index]) {
                    $value += $modifiers[$index]['value'];
                    $modifiers[$index]['applied'] = true;
                }
            }
        }
        if ($sets !== []) {
            // Highest priority wins; on a tie the first declared wins (stable, documented).
            usort($sets, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
            $winner = $sets[0][1];
            $value = $modifiers[$winner]['value'];
            foreach (array_keys($modifiers) as $i) {
                $modifiers[$i]['applied'] = $i === $winner; // a winning `set` overrides every add
            }
        }
        $trace?->record($id, (string) $definition['path'], $base, $modifiers, $value);

        return $value;
    }

    /**
     * @param array<string, mixed> $effect
     * @return list<array{0: int|float|bool|string|null, 1: array<string, scalar|null>}>
     */
    private function effectInstances(array $effect, SheetScope $scope): array
    {
        $evaluator = new FormulaEvaluator($scope);
        if (isset($effect['when']) && $evaluator->evaluate($effect['when']) !== true) {
            return [];
        }
        if (!isset($effect['source']['collection'])) {
            return [[$evaluator->evaluate($effect['value']), ['kind' => 'rule']]];
        }
        $collection = (string) $effect['source']['collection'];
        $out = [];
        foreach ($scope->collection($collection) as $item) {
            if (isset($effect['source']['where']) && $evaluator->evaluateWithItem($effect['source']['where'], $item) !== true) {
                continue;
            }
            $out[] = [$evaluator->evaluateWithItem($effect['value'], $item), ['kind' => 'item', 'collection' => $collection, 'item_id' => (string) ($item['id'] ?? '')]];
        }

        return $out;
    }

    /**
     * The sheet with every derived value written at its path, plus the decision trace and any
     * evaluation issue. Derived values are never stored as authority (ADR-001 §4).
     *
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: list<ValidationIssue>}
     */
    public function materialize(array $sheet, array $grants): array
    {
        $trace = new DecisionTrace();
        $scope = $this->scope($sheet, $grants, $trace);
        $issues = [];
        $out = $sheet;
        foreach ($this->derived as $id => $definition) {
            try {
                $value = $scope->derived($id);
            } catch (FormulaException $e) {
                $issues[] = new ValidationIssue('derived.evaluation_failed', Severity::Error, [], [(string) $definition['path']], ['derived' => $id, 'reason' => $e->ruleId]);
                continue;
            }
            SheetProjector::write($out, JsonPointer::parse((string) $definition['path'])->segments, $value);
        }

        return [$out, $trace->entries(), $issues];
    }

    /**
     * Whole-candidate mechanical checks (SPEC-SPRINT-001 rule 8). Paths are reported; operation
     * attribution is done by the caller.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @param array<string, int> $grants
     * @param list<string> $changedPaths pointers changed by this change set
     * @return list<ValidationIssue>
     */
    public function check(array $base, array $candidate, Phase $phase, array $grants, array $changedPaths): array
    {
        $issues = [];
        $scope = $this->scope($candidate, $grants);
        $baseScope = $this->scope($base, $grants);
        $changed = array_flip($changedPaths);

        foreach ($this->rules['creation']['choices'] ?? [] as $choice) {
            $value = $scope->pointer((string) $choice['path']);
            if ($value === null || $value === '') {
                continue;
            }
            $option = null;
            foreach ($choice['options'] as $candidateOption) {
                if ($candidateOption['value'] === $value) {
                    $option = $candidateOption;
                    break;
                }
            }
            if ($option === null) {
                $issues[] = $this->issue('choice.unknown_option', [(string) $choice['path']], ['choice' => $choice['id']]);
                continue;
            }
            if (isset($option['requires']) && $this->truth($option['requires'], $scope, $issues, [(string) $choice['path']]) === false) {
                $issues[] = $this->issue('choice.prerequisite_unmet', [(string) $choice['path']], ['choice' => $choice['id'], 'option' => (string) $option['value']]);
            }
        }

        foreach ($this->fixedFields($candidate, $grants) as $path => $fix) {
            if (!self::holds($scope->pointer($path), $fix)) {
                $issues[] = $this->issue('choice.fixed_value', [$path], ['choice' => $fix['choice'], 'option' => (string) $fix['option'], 'value' => $fix['value']]);
            }
        }

        foreach ($this->rules['constraints'] ?? [] as $constraint) {
            $when = $constraint['phase'] ?? 'always';
            if ($when !== 'always' && $when !== $phase->value) {
                continue;
            }
            if ($this->truth($constraint['assert'], $scope, $issues, $constraint['paths']) === false) {
                $issues[] = $this->issue('constraint.failed', $constraint['paths'], ['rule' => $constraint['id']]);
            }
        }

        foreach ($this->rules['progression']['tracks'] ?? [] as $track) {
            $path = (string) $track['path'];
            if (!isset($changed[$path])) {
                continue;
            }
            $from = (int) $baseScope->pointer($path);
            $to = (int) $scope->pointer($path);
            try {
                $measure = (new FormulaEvaluator($scope))->evaluate($track['measure']);
            } catch (FormulaException $e) {
                $issues[] = $this->issue($e->ruleId, [$path], ['track' => $track['id']]);
                continue;
            }
            $earned = 0;
            foreach ($track['thresholds'] as $threshold) {
                if (is_numeric($measure) && $measure >= $threshold) {
                    $earned++;
                }
            }
            if ($to < $from) {
                $issues[] = $this->issue('progression.level_decrease', [$path], ['track' => $track['id'], 'from' => $from, 'to' => $to]);
            } elseif ($to - $from > (int) ($track['max_step'] ?? 1)) {
                $issues[] = $this->issue('progression.level_skip', [$path], ['track' => $track['id'], 'from' => $from, 'to' => $to, 'max_step' => (int) ($track['max_step'] ?? 1)]);
            }
            if ($to > $earned) {
                $issues[] = $this->issue('progression.level_not_earned', [$path], ['track' => $track['id'], 'earned' => $earned, 'requested' => $to]);
            }
        }

        foreach ($this->rules['resources'] ?? [] as $resource) {
            $path = (string) $resource['path'];
            $value = $scope->pointer($path);
            $max = $this->number($resource['max'], $scope, $issues, [$path]);
            if ($max === null || !is_numeric($value)) {
                continue;
            }
            if ($value > $max || $value < (int) ($resource['min'] ?? 0)) {
                $issues[] = $this->issue('resource.out_of_range', [$path], ['resource' => $resource['id'], 'min' => (int) ($resource['min'] ?? 0), 'max' => $max]);
            }
        }

        foreach ($this->rules['inventory'] ?? [] as $inventory) {
            if (!isset($inventory['capacity'], $inventory['weight_field'])) {
                continue;
            }
            $collection = (string) $inventory['collection'];
            $quantity = $inventory['quantity_field'] ?? null;
            $weight = ['item' => $inventory['weight_field']];
            $load = $this->number(['op' => 'sum', 'collection' => $collection, 'value' => $quantity === null ? $weight : ['op' => 'multiply', 'args' => [$weight, ['item' => $quantity]]]], $scope, $issues, [$collection]);
            $capacity = $this->number($inventory['capacity'], $scope, $issues, [$collection]);
            if ($load !== null && $capacity !== null && $load > $capacity) {
                $issues[] = $this->issue('inventory.over_capacity', [$collection], ['inventory' => $inventory['id'], 'load' => $load, 'capacity' => $capacity]);
            }
        }

        $before = self::balancesOf($this->budgetView($base, $grants));
        foreach (self::balancesOf($this->budgetView($candidate, $grants)) as $budget => $balance) {
            if ($balance < 0 && $balance < ($before[$budget] ?? 0)) {
                $issues[] = $this->issue('budget.overdrawn', [], ['budget' => $budget, 'balance' => $balance]);
            }
        }

        return $issues;
    }

    /**
     * Status of each declared creation step, in order. A step is complete when its required paths
     * are filled, its `complete_when` holds and no validation issue touches its paths.
     *
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     * @return array{complete: bool, steps: list<array<string, mixed>>, issues: list<ValidationIssue>}
     */
    public function creationProgress(array $sheet, array $grants): array
    {
        $scope = $this->scope($sheet, $grants);
        $issues = $this->check($sheet, $sheet, Phase::Creation, $grants, []);
        [, , $derivedIssues] = $this->materialize($sheet, $grants);
        $issues = [...$issues, ...$derivedIssues];
        $steps = [];
        $allComplete = true;
        $previousComplete = true;
        foreach ($this->rules['creation']['steps'] ?? [] as $step) {
            $missing = [];
            foreach ($step['required'] ?? [] as $path) {
                $value = $scope->pointer((string) $path);
                if ($value === null || $value === '') {
                    $missing[] = (string) $path;
                }
            }
            $local = [];
            $conditionMet = !isset($step['complete_when']) || $this->truth($step['complete_when'], $scope, $local, []) === true;
            $blocking = 0;
            foreach ($issues as $issue) {
                if ($issue->paths === [] || $this->touchesStep($issue->paths, $step)) {
                    $blocking++;
                }
            }
            $complete = $missing === [] && $conditionMet && $blocking === 0;
            $steps[] = [
                'id' => $step['id'],
                'available' => $previousComplete,
                'complete' => $complete,
                'missing' => $missing,
                'condition_met' => $conditionMet,
                'issues' => $blocking,
            ];
            $previousComplete = $previousComplete && $complete;
            $allComplete = $allComplete && $complete;
        }

        return ['complete' => $allComplete && $issues === [], 'steps' => $steps, 'issues' => $issues];
    }

    /**
     * For each declared choice, which options the sheet currently allows (prerequisites only;
     * the server still validates every submission). Lets a client grey out options with the
     * same rules the engine applies.
     *
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     * @return list<array{id: string, path: string, value: mixed, options: list<array{value: mixed, available: bool}>}>
     */
    public function choiceAvailability(array $sheet, array $grants): array
    {
        $scope = $this->scope($sheet, $grants);
        $out = [];
        foreach ($this->rules['creation']['choices'] ?? [] as $choice) {
            $options = [];
            foreach ($choice['options'] as $option) {
                $ignored = [];
                $options[] = ['value' => $option['value'], 'available' => !isset($option['requires']) || $this->truth($option['requires'], $scope, $ignored, []) === true];
            }
            $value = $scope->pointer((string) $choice['path']);
            $out[] = ['id' => (string) $choice['id'], 'path' => (string) $choice['path'], 'value' => $value === '' ? null : $value, 'options' => $options, 'fixes' => []];
        }
        // What the selected options hold, so a client can lock those controls and say why.
        foreach ($this->fixedFields($sheet, $grants) as $path => $fix) {
            foreach ($out as $i => $row) {
                if ($row['id'] === $fix['choice']) {
                    $out[$i]['fixes'][] = ['path' => $path, 'value' => $fix['value'], 'label' => $fix['label'], 'mode' => $fix['mode']];
                }
            }
        }

        return $out;
    }

    /**
     * Fields held by the selected options of the sheet's choices (`options[].fixes`): pointer =>
     * the value it must hold and which choice/option holds it. Values are formulas over the sheet.
     * A fix whose formula fails is skipped here; RulesCompatibility rejects malformed ones.
     *
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     * @return array<string, array{value: int|float|bool|string|null, choice: string, option: mixed, label: string|null, mode: string}>
     */
    public function fixedFields(array $sheet, array $grants): array
    {
        $fixed = [];
        $scope = null;
        foreach ($this->rules['creation']['choices'] ?? [] as $choice) {
            [, $value] = SheetProjector::read($sheet, JsonPointer::parse((string) $choice['path'])->segments);
            if ($value === null || $value === '') {
                continue;
            }
            foreach ($choice['options'] as $option) {
                if ($option['value'] !== $value || !isset($option['fixes'])) {
                    continue;
                }
                $scope ??= $this->scope($sheet, $grants);
                foreach ($option['fixes'] as $fix) {
                    try {
                        $result = (new FormulaEvaluator($scope))->evaluate($fix['value']);
                    } catch (FormulaException) {
                        continue;
                    }
                    $fixed[JsonPointer::parse((string) $fix['path'])->toString()] = [
                        'value' => $result, 'choice' => (string) $choice['id'], 'option' => $option['value'], 'label' => $fix['label'] ?? null, 'mode' => (string) ($fix['mode'] ?? 'exact'),
                    ];
                }
            }
        }

        return $fixed;
    }

    /** Whether a value satisfies a hold: equal (exact) or not below it (at_least). @param array{value: mixed, mode: string} $fix */
    public static function holds(mixed $value, array $fix): bool
    {
        if ($fix['mode'] === 'at_least') {
            return is_numeric($value) && is_numeric($fix['value']) && $value >= $fix['value'];
        }

        return $value === $fix['value'];
    }

    /** @param list<string> $paths @param array<string, mixed> $step */
    private function touchesStep(array $paths, array $step): bool
    {
        $targets = [...($step['paths'] ?? []), ...($step['required'] ?? [])];
        foreach ($paths as $path) {
            foreach ($targets as $target) {
                $prefix = str_ends_with((string) $target, '/*') ? substr((string) $target, 0, -1) : $target . '/';
                if ($path === $target || str_starts_with($path, (string) $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Evaluates a cost model for one increment from `$current` to `$current + 1`.
     *
     * @param array<string, mixed> $model
     * @param array<string, mixed> $sheet
     * @param array<string, int> $grants
     */
    public function cost(array $model, int $current, array $sheet, array $grants): int
    {
        return match ($model['type']) {
            'fixed_per_dot' => (int) $model['amount'],
            'current_rating_multiplier' => $current === 0 ? (int) ($model['when_zero'] ?? $model['multiplier']) : $current * (int) $model['multiplier'],
            'new_rating_multiplier' => ($current + 1) * (int) $model['multiplier'],
            'formula' => $this->formulaCost($model['formula'], $current, $sheet, $grants),
            default => throw new InvalidArgumentException('UNSUPPORTED_CAPABILITY: cost model'),
        };
    }

    /** @param array<string, mixed> $formula @param array<string, mixed> $sheet @param array<string, int> $grants */
    private function formulaCost(array $formula, int $current, array $sheet, array $grants): int
    {
        $value = (new FormulaEvaluator($this->scope($sheet, $grants)->withVariables(['current' => $current, 'new' => $current + 1])))->evaluate($formula);
        if (!is_int($value) && !is_float($value)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'number']);
        }
        $cost = (int) floor($value);
        if ($cost < 0) {
            throw new FormulaException('formula.negative_cost');
        }

        return $cost;
    }

    /** @param list<ValidationIssue> $issues @param list<string> $paths */
    private function truth(mixed $formula, SheetScope $scope, array &$issues, array $paths): ?bool
    {
        try {
            $value = (new FormulaEvaluator($scope))->evaluate($formula);
        } catch (FormulaException $e) {
            $issues[] = $this->issue($e->ruleId, $paths, $e->params);

            return null;
        }
        if (!is_bool($value)) {
            $issues[] = $this->issue('formula.type_mismatch', $paths, ['expected' => 'boolean']);

            return null;
        }

        return $value;
    }

    /** @param list<ValidationIssue> $issues @param list<string> $paths */
    private function number(mixed $formula, SheetScope $scope, array &$issues, array $paths): int|float|null
    {
        try {
            $value = (new FormulaEvaluator($scope))->evaluate($formula);
        } catch (FormulaException $e) {
            $issues[] = $this->issue($e->ruleId, $paths, $e->params);

            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            $issues[] = $this->issue('formula.type_mismatch', $paths, ['expected' => 'number']);

            return null;
        }

        return $value;
    }

    /** @param list<string> $paths @param array<string, scalar|null> $params */
    private function issue(string $rule, array $paths, array $params = []): ValidationIssue
    {
        return new ValidationIssue($rule, Severity::Error, [], array_values($paths), $params);
    }
}
