<?php

declare(strict_types=1);

namespace Novtt\Engine\Resolution;

use InvalidArgumentException;
use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Formula\FormulaEvaluator;
use Novtt\Engine\Formula\FormulaException;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Rules\SheetScope;

/**
 * Turns a roll declared in novtt.rules/2 (`rolls[]`) into a concrete SumRoll or PoolRoll for one
 * sheet, then resolves it with the strategy named by the declaration. Strategies are generic
 * primitives (`sum`, `pool`), never game identifiers. Request parameters are only those the
 * declaration exposes, inside its bounds: a client can choose an input among declared targets or
 * a difficulty within limits, never faces, dice counts or modifiers (AGENTS.md, ADR 0004).
 */
final readonly class PresetRolls
{
    /** @param array<string, mixed> $rules */
    public function __construct(private array $rules, private SchemaNavigator $schema)
    {
    }

    /** @return array<string, mixed> */
    public function declaration(string $rollId): array
    {
        foreach ($this->rules['rolls'] ?? [] as $roll) {
            if ($roll['id'] === $rollId) {
                return $roll;
            }
        }
        throw new RollRejected('roll.unknown', ['roll' => $rollId]);
    }

    /** @param array<string, mixed> $params */
    public function prepare(string $rollId, SheetScope $scope, array $params = []): SumRoll|PoolRoll
    {
        $roll = $this->declaration($rollId);
        $allowed = ['inputs', 'edge', 'difficulty', 'required', 'target'];
        foreach (array_keys($params) as $param) {
            if (!in_array($param, $allowed, true)) {
                throw new RollRejected('roll.unknown_param', ['param' => (string) $param]);
            }
        }
        $variables = $this->inputs($roll, $scope, (array) ($params['inputs'] ?? []));
        $scope = $scope->withVariables($variables);

        try {
            return match ($roll['strategy']) {
                SumStrategy::NAME => $this->sum($roll, $scope, $params),
                PoolStrategy::NAME => $this->pool($roll, $scope, $params),
                'keep' => $this->keep($roll, $scope, $params),
                default => throw new RollRejected('UNSUPPORTED_CAPABILITY', ['strategy' => (string) $roll['strategy']]),
            };
        } catch (FormulaException $e) {
            throw new RollRejected($e->ruleId, $e->params);
        } catch (RollRejected $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            throw new RollRejected($e->getMessage());
        }
    }

    /** @param array<string, mixed> $params */
    public function roll(string $rollId, SheetScope $scope, RandomSource $random, array $params = []): RollOutcome
    {
        $spec = $this->prepare($rollId, $scope, $params);

        return $spec instanceof SumRoll ? (new SumStrategy($random))->roll($spec) : (new PoolStrategy($random))->roll($spec);
    }

    /**
     * @param array<string, mixed> $roll
     * @param array<string, mixed> $chosen input name => pointer
     * @return array<string, int|float|bool|string|null>
     */
    private function inputs(array $roll, SheetScope $scope, array $chosen): array
    {
        $declared = (array) ($roll['inputs'] ?? []);
        $variables = [];
        foreach (array_keys($chosen) as $name) {
            if (!isset($declared[$name])) {
                throw new RollRejected('roll.unknown_input', ['input' => (string) $name]);
            }
        }
        foreach ($declared as $name => $input) {
            $pointer = $chosen[$name] ?? null;
            if (!is_string($pointer)) {
                throw new RollRejected('roll.input_required', ['input' => (string) $name]);
            }
            $allowed = [];
            foreach ($input['targets'] as $target) {
                foreach ($this->schema->expand((string) $target) as $expanded) {
                    $allowed[$expanded->toString()] = true;
                }
            }
            if (!isset($allowed[$pointer])) {
                throw new RollRejected('roll.input_not_allowed', ['input' => (string) $name]);
            }
            $value = $scope->pointer($pointer);
            $variables[(string) $name] = is_int($value) || is_float($value) ? $value : 0;
        }

        return $variables;
    }

    /** @param array<string, mixed> $roll @param array<string, mixed> $params */
    private function sum(array $roll, SheetScope $scope, array $params): SumRoll
    {
        $modifier = isset($roll['modifier']) ? $this->integer($roll['modifier'], $scope) : 0;
        $target = $this->bounded($roll['target'] ?? null, $params['target'] ?? null, 'target');
        $base = DiceExpression::parse((string) $roll['dice'], $target, $roll['critical_at_least'] ?? null, $roll['fumble_at_most'] ?? null);
        $spec = new SumRoll($base->groups, $base->modifier + $modifier, $target, $base->criticalAtLeast, $base->fumbleAtMost);
        $edge = $params['edge'] ?? 'normal';
        if ($edge !== 'normal') {
            if (!isset($roll['edge']) || !in_array($edge, ['advantage', 'disadvantage'], true)) {
                throw new RollRejected('roll.edge_not_allowed');
            }
            $spec = $spec->withEdge($edge === 'advantage' ? 'highest' : 'lowest', (int) ($roll['edge']['extra_dice'] ?? 1));
        }

        return $spec;
    }

    /**
     * `keep`: N dice of F faces, keeping the K highest or lowest (a SumRoll with one keep group).
     * Count and mode come from formulas over the sheet, so a preset can say "a die per point,
     * keep the best; at 0, two dice and keep the worst" without game code in the engine.
     *
     * @param array<string, mixed> $roll @param array<string, mixed> $params
     */
    private function keep(array $roll, SheetScope $scope, array $params): SumRoll
    {
        $count = $this->integer($roll['count'], $scope);
        if ($count < 1) {
            throw new RollRejected('roll.no_dice', ['count' => $count]);
        }
        $mode = isset($roll['keep_mode']) ? (new FormulaEvaluator($scope))->evaluate($roll['keep_mode']) : 'highest';
        if (!in_array($mode, ['highest', 'lowest'], true)) {
            throw new RollRejected('roll.keep_mode_invalid');
        }
        $keep = min($count, (int) ($roll['keep'] ?? 1));
        $modifier = isset($roll['modifier']) ? $this->integer($roll['modifier'], $scope) : 0;
        $target = $this->bounded($roll['target'] ?? null, $params['target'] ?? null, 'target');
        if (($params['edge'] ?? 'normal') !== 'normal') {
            throw new RollRejected('roll.edge_not_allowed');
        }

        return new SumRoll([new DiceGroup($count, (int) $roll['faces'], 1, $mode, $keep)], $modifier, $target, $roll['critical_at_least'] ?? null, $roll['fumble_at_most'] ?? null);
    }

    /** @param array<string, mixed> $roll @param array<string, mixed> $params */
    private function pool(array $roll, SheetScope $scope, array $params): PoolRoll
    {
        $count = max(0, $this->integer($roll['count'], $scope));

        return new PoolRoll(
            $count,
            (int) $roll['faces'],
            (int) $this->bounded($roll['difficulty'], $params['difficulty'] ?? null, 'difficulty'),
            (int) ($this->bounded($roll['required'] ?? ['default' => 1, 'min' => 1, 'max' => 1], $params['required'] ?? null, 'required')),
            $roll['cancel_at_most'] ?? null,
            $roll['bonus_at_least'] ?? null,
            (int) ($roll['bonus_successes'] ?? 0),
            $roll['explode_at_least'] ?? null,
            $roll['botch_at_most'] ?? null,
        );
    }

    /**
     * A parameter declared as {default, min, max}: the request may set it within bounds.
     *
     * @param array<string, mixed>|null $declaration
     */
    private function bounded(?array $declaration, mixed $requested, string $name): ?int
    {
        if ($declaration === null) {
            if ($requested !== null) {
                throw new RollRejected('roll.unknown_param', ['param' => $name]);
            }

            return null;
        }
        if ($requested === null) {
            return isset($declaration['default']) ? (int) $declaration['default'] : null;
        }
        if (!is_int($requested) || $requested < (int) $declaration['min'] || $requested > (int) $declaration['max']) {
            throw new RollRejected('roll.param_out_of_bounds', ['param' => $name, 'min' => (int) $declaration['min'], 'max' => (int) $declaration['max']]);
        }

        return $requested;
    }

    private function integer(mixed $formula, SheetScope $scope): int
    {
        $value = (new FormulaEvaluator($scope))->evaluate($formula);
        if (!is_int($value) && !is_float($value)) {
            throw new FormulaException('formula.type_mismatch', ['expected' => 'number']);
        }

        return (int) floor($value);
    }
}
