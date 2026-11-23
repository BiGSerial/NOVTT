<?php

declare(strict_types=1);

namespace Novtt\Engine\Rules;

use Novtt\Engine\Formula\FormulaException;
use Novtt\Engine\Formula\Scope;
use Novtt\Engine\Formula\StepMeter;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Presets\SheetProjector;
use Novtt\Engine\Sheets\JsonPointer;

/**
 * Scope over one sheet: pointer reads fall back to the schema default (then minimum, then 0 for
 * numbers), derived values are computed lazily through Mechanics with memoization and a cycle
 * guard, budgets come from the precomputed ledger view.
 */
final class SheetScope implements Scope
{
    /** @var array<string, int|float|bool|string|null> */
    private array $derivedValues = [];
    /** @var array<string, true> */
    private array $computing = [];

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, array{amount: int, granted: int, spent: int}> $budgets
     * @param array<string, int|float|bool|string|null> $variables
     */
    public function __construct(
        private readonly array $sheet,
        private readonly SchemaNavigator $schema,
        private readonly ?Mechanics $mechanics,
        private readonly array $budgets = [],
        private readonly array $variables = [],
        private readonly ?DecisionTrace $trace = null,
        private ?StepMeter $meter = null,
    ) {
        $this->meter ??= new StepMeter();
    }

    public function meter(): StepMeter
    {
        return $this->meter ??= new StepMeter();
    }

    /** @param array<string, int|float|bool|string|null> $variables */
    public function withVariables(array $variables): self
    {
        $copy = new self($this->sheet, $this->schema, $this->mechanics, $this->budgets, $variables + $this->variables, $this->trace, $this->meter());
        $copy->derivedValues = $this->derivedValues;

        return $copy;
    }

    public function pointer(string $pointer): mixed
    {
        $parsed = JsonPointer::parse($pointer);
        if ($this->mechanics !== null && ($id = $this->mechanics->derivedAt($pointer)) !== null) {
            return $this->derived($id);
        }
        [$exists, $value] = SheetProjector::read($this->sheet, $parsed->segments);
        if ($exists && $value !== null) {
            return $value;
        }
        $node = $this->schema->at($parsed) ?? [];
        if (array_key_exists('default', $node)) {
            return $node['default'];
        }
        $type = SchemaNavigator::type($node);
        if ($type === 'integer' || $type === 'number') {
            return $node['minimum'] ?? 0;
        }

        return match ($type) {
            'boolean' => false,
            'string' => '',
            default => null,
        };
    }

    public function derived(string $id): mixed
    {
        if (array_key_exists($id, $this->derivedValues)) {
            return $this->derivedValues[$id];
        }
        if ($this->mechanics === null) {
            throw new FormulaException('formula.unknown_derived', ['derived' => $id]);
        }
        if (isset($this->computing[$id])) {
            throw new FormulaException('formula.dependency_cycle', ['derived' => $id]);
        }
        $this->computing[$id] = true;
        try {
            $value = $this->mechanics->computeDerived($id, $this, $this->trace);
        } finally {
            unset($this->computing[$id]);
        }

        return $this->derivedValues[$id] = $value;
    }

    public function budget(string $id, string $measure): int
    {
        $budget = $this->budgets[$id] ?? throw new FormulaException('formula.unknown_budget', ['budget' => $id]);

        return match ($measure) {
            'amount' => $budget['amount'],
            'granted' => $budget['granted'],
            'spent' => $budget['spent'],
            'balance' => $budget['amount'] + $budget['granted'] - $budget['spent'],
            default => throw new FormulaException('formula.invalid_budget_measure'),
        };
    }

    public function variable(string $name): mixed
    {
        if (!array_key_exists($name, $this->variables)) {
            throw new FormulaException('formula.unknown_variable', ['var' => $name]);
        }

        return $this->variables[$name];
    }

    public function collection(string $pointer): array
    {
        [$exists, $value] = SheetProjector::read($this->sheet, JsonPointer::parse($pointer)->segments);
        if (!$exists || !is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return array<string, int|float|bool|string|null> */
    public function computedDerived(): array
    {
        return $this->derivedValues;
    }
}
