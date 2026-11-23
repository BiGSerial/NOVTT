<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

use InvalidArgumentException;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Presets\SheetProjector;
use Novtt\Engine\Formula\FormulaException;
use Novtt\Engine\Rules\Mechanics;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Evaluates a whole set of typed operations against the official sheet (SPEC-SPRINT-001 rules
 * 5, 8, 11): authorization per field, preconditions, application, structural validation of the
 * full candidate, phase limits, structural dependencies and budget costs with per-increment
 * provenance. Never mutates its inputs; the caller persists the outcome atomically.
 *
 * Budget ledger: the candidate keeps `/_ledger`, a list of {path, level, budget, cost}. Changes are
 * charged in operation order. Raising a
 * rating from r to r+1 records level r+1 paid by the first applicable cost rule whose budget can
 * afford it; lowering a rating removes the entries of the removed levels and refunds each one to
 * the budget that paid it. Grants add balance and never spend.
 */
final readonly class SheetChangeEngine
{
    public const LEDGER = '_ledger';

    private Mechanics $mechanics;

    /**
     * @param array<string, mixed> $sheetSchema the pinned version's sheet JSON Schema (assoc)
     * @param array<string, mixed>|object $sheetSchemaObject the same schema as an object tree
     * @param array<string, mixed> $rules novtt.rules/1
     */
    public function __construct(
        private SheetLayout $layout,
        private array $sheetSchema,
        private array|object $sheetSchemaObject,
        private array $rules,
        private StructuralValidator $structure,
    ) {
        $this->mechanics = new Mechanics($rules, new SchemaNavigator($sheetSchema));
    }

    public function mechanics(): Mechanics
    {
        return $this->mechanics;
    }

    /**
     * @param array<string, mixed> $sheet official sheet (may contain /_ledger)
     * @param list<ChangeOperation> $operations
     * @param array<string, int> $grants granted amount per budget id
     */
    public function evaluate(array $sheet, array $operations, Actor $actor, Phase $phase, array $grants = []): ChangeOutcome
    {
        $schema = new SchemaNavigator($this->sheetSchema);
        $issues = [];
        $candidate = $sheet;
        $touched = []; // pointer => list<op id>

        foreach ($operations as $op) {
            $issue = $this->authorize($op, $actor, $schema);
            if ($issue !== null) {
                $issues[] = $issue;
                continue;
            }
            $issue = $this->apply($candidate, $op);
            if ($issue !== null) {
                $issues[] = $issue;
                continue;
            }
            $touched[$op->path->toString()][] = $op->key;
        }
        if ($issues !== []) {
            return new ChangeOutcome(new ValidationResult($issues), $sheet, $this->balances($sheet, $grants));
        }

        $document = $candidate;
        unset($document[self::LEDGER]);
        $structural = $this->structure->validate($document, $this->sheetSchemaObject);
        foreach ($structural->issues as $issue) {
            $issues[] = new ValidationIssue($issue->ruleId, $issue->severity, $this->opsFor($issue->paths, $touched), $issue->paths, $issue->params);
        }

        [$candidate, $budgetIssues] = $this->settleBudgets($sheet, $candidate, $phase, $grants, $schema, $touched);
        $issues = [...$issues, ...$budgetIssues, ...$this->phaseLimits($candidate, $phase, $schema, $touched), ...$this->dependencies($candidate, $touched)];
        foreach ($this->mechanics->check($sheet, $candidate, $phase, $grants, array_keys($touched)) as $issue) {
            $issues[] = new ValidationIssue($issue->ruleId, $issue->severity, $this->opsFor($issue->paths, $touched), $issue->paths, $issue->params);
        }

        return new ChangeOutcome(new ValidationResult($issues), $candidate, $this->balances($candidate, $grants));
    }

    /** @param array<string, mixed> $sheet @param array<string, int> $grants @return array<string, int> */
    public function balances(array $sheet, array $grants): array
    {
        return Mechanics::balancesOf($this->mechanics->budgetView($sheet, $grants));
    }

    private function authorize(ChangeOperation $op, Actor $actor, SchemaNavigator $schema): ?ValidationIssue
    {
        $path = $op->path;
        // Same answer for hidden, master-only and undeclared paths: players learn nothing.
        $denied = new ValidationIssue('op.path_not_allowed', Severity::Error, [$op->key], $actor === Actor::Player ? [] : [$path->toString()]);
        if (str_starts_with($path->segments[0], '_')) {
            return $denied;
        }
        if ($this->mechanics->isDerivedPath($path)) {
            // Derived values are computed by the engine; nobody writes them, not even the master.
            return new ValidationIssue('op.derived_read_only', Severity::Error, [$op->key], [$path->toString()]);
        }
        $element = $this->layout->element($path);
        $isItemOp = in_array($op->type, [OperationType::AddItem, OperationType::SetItemField, OperationType::RemoveItem, OperationType::ReorderItems], true);
        if ($element === null || ($element['kind'] === 'collection') !== $isItemOp) {
            return $denied;
        }
        if ($actor === Actor::Player && !$element['access']->playerCanPropose()) {
            return $denied;
        }
        if ($op->type === OperationType::SetItemField) {
            $access = $this->layout->itemFieldAccess($path, (string) $op->field);
            if (!isset($element['item_fields'][$op->field]) || ($actor === Actor::Player && !$access->playerCanPropose())) {
                return $denied;
            }
        }
        if ($op->type === OperationType::AddItem && is_array($op->value)) {
            foreach (array_keys($op->value) as $field) {
                if (!isset($element['item_fields'][$field]) || ($actor === Actor::Player && !$this->layout->itemFieldAccess($path, (string) $field)->playerCanPropose())) {
                    return $denied;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $sheet */
    private function apply(array &$sheet, ChangeOperation $op): ?ValidationIssue
    {
        $segments = $op->path->segments;
        [$exists, $current] = SheetProjector::read($sheet, $segments);
        $conflict = static fn (string $rule, array $params = []): ValidationIssue => new ValidationIssue($rule, Severity::Error, [$op->key], [$op->path->toString()], $params);

        if (in_array($op->type, [OperationType::SetField, OperationType::UnsetField], true)) {
            if ($op->hasExpected && ($exists ? $current : null) !== $op->expected) {
                return $conflict('op.precondition_failed');
            }
            if ($op->type === OperationType::SetField) {
                SheetProjector::write($sheet, $segments, $op->value);
            } elseif ($exists) {
                self::remove($sheet, $segments);
            }

            return null;
        }

        $items = $exists ? $current : [];
        if (!is_array($items) || ($items !== [] && !array_is_list($items))) {
            return $conflict('op.not_a_collection');
        }
        $index = null;
        foreach ($items as $i => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $op->itemId) {
                $index = $i;
            }
        }
        $max = $this->collectionMax($op->path);
        switch ($op->type) {
            case OperationType::AddItem:
                if ($index !== null) {
                    return $conflict('op.item_exists', ['item_id' => (string) $op->itemId]);
                }
                if ($max !== null && count($items) >= $max) {
                    return $conflict('op.collection_full', ['max_items' => $max]);
                }
                $items[] = ['id' => $op->itemId] + (array) $op->value;
                break;
            case OperationType::SetItemField:
                if ($index === null) {
                    return $conflict('op.item_not_found', ['item_id' => (string) $op->itemId]);
                }
                if ($op->hasExpected && ($items[$index][$op->field] ?? null) !== $op->expected) {
                    return $conflict('op.precondition_failed');
                }
                $items[$index][(string) $op->field] = $op->value;
                break;
            case OperationType::RemoveItem:
                if ($index === null) {
                    return $conflict('op.item_not_found', ['item_id' => (string) $op->itemId]);
                }
                array_splice($items, $index, 1);
                break;
            case OperationType::ReorderItems:
                $ids = array_map(static fn (array $item): string => (string) $item['id'], $items);
                $order = (array) $op->order;
                $sortedIds = $ids;
                $sortedOrder = $order;
                sort($sortedIds);
                sort($sortedOrder);
                if ($sortedIds !== $sortedOrder) {
                    return $conflict('op.order_mismatch');
                }
                $byId = array_combine($ids, $items);
                $items = array_map(static fn (string $id): array => $byId[$id], $order);
                break;
            default:
                throw new InvalidArgumentException('unreachable');
        }
        SheetProjector::write($sheet, $segments, $items);

        return null;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @param array<string, int> $grants
     * @param array<string, list<string>> $touched
     * @return array{0: array<string, mixed>, 1: list<ValidationIssue>}
     */
    private function settleBudgets(array $base, array $candidate, Phase $phase, array $grants, SchemaNavigator $schema, array $touched): array
    {
        $issues = [];
        $ledger = array_values((array) ($base[self::LEDGER] ?? []));
        // Amounts may depend on the sheet (e.g. a priority choice or a level): use the candidate's.
        $balances = Mechanics::balancesOf($this->mechanics->budgetView($candidate, $grants, $ledger));
        $rated = $this->ratedTargets($schema);

        // Refunds first (lowered ratings), then charges, so a player can move points.
        $changes = [];
        foreach ($rated as $path => $costRules) {
            $pointer = JsonPointer::parse($path);
            $from = $this->rating($base, $pointer, $schema);
            $to = $this->rating($candidate, $pointer, $schema);
            if ($from !== $to) {
                $changes[$path] = [$from, $to, $costRules];
            }
        }
        // Charge in the author's operation order (then by path): deterministic and independent
        // of how a storage engine orders object keys (PostgreSQL JSONB does not keep them).
        $order = array_flip(array_keys($touched));
        uksort($changes, static fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
        foreach ($changes as $path => [$from, $to]) {
            if ($to < $from) {
                foreach ($ledger as $i => $entry) {
                    if ($entry['path'] === $path && (int) $entry['level'] > $to) {
                        $balances[$entry['budget']] = ($balances[$entry['budget']] ?? 0) + (int) $entry['cost'];
                        unset($ledger[$i]);
                    }
                }
            }
        }
        foreach ($changes as $path => [$from, $to, $costRules]) {
            $phaseRules = array_values(array_filter($costRules, static fn (array $r): bool => $r['phase'] === $phase->value));
            for ($level = $from + 1; $level <= $to; $level++) {
                $paid = false;
                $needed = null;
                foreach ($phaseRules as $rule) {
                    if ($level <= (int) ($rule['free_dots'] ?? 0)) {
                        $paid = true; // printed/free dot: no ledger entry
                        break;
                    }
                    try {
                        $cost = $this->mechanics->cost($rule['model'], $level - 1, $candidate, $grants);
                    } catch (FormulaException $e) {
                        $issues[] = new ValidationIssue($e->ruleId, Severity::Error, $touched[$path] ?? [], [$path], ['rule' => $rule['id']]);
                        $paid = true;
                        break;
                    }
                    $needed ??= ['budget' => $rule['budget'], 'cost' => $cost];
                    if (($balances[$rule['budget']] ?? 0) >= $cost) {
                        $balances[$rule['budget']] -= $cost;
                        $ledger[] = ['path' => $path, 'level' => $level, 'budget' => $rule['budget'], 'cost' => $cost, 'rule' => $rule['id']];
                        $paid = true;
                        break;
                    }
                }
                if (!$paid) {
                    $issues[] = $phaseRules === []
                        ? new ValidationIssue('budget.no_cost_rule_for_phase', Severity::Error, $touched[$path] ?? [], [$path], ['phase' => $phase->value])
                        : new ValidationIssue('budget.insufficient', Severity::Error, $touched[$path] ?? [], [$path], [
                            'budget' => (string) $needed['budget'], 'required' => (int) $needed['cost'], 'available' => (int) ($balances[$needed['budget']] ?? 0), 'level' => $level,
                        ]);
                    break;
                }
            }
        }
        $candidate[self::LEDGER] = array_values($ledger);
        if ($candidate[self::LEDGER] === []) {
            unset($candidate[self::LEDGER]);
        }

        return [$candidate, $issues];
    }

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, list<string>> $touched
     * @return list<ValidationIssue>
     */
    private function phaseLimits(array $sheet, Phase $phase, SchemaNavigator $schema, array $touched): array
    {
        $issues = [];
        foreach ($this->rules['phase_limits'] ?? [] as $limit) {
            if ($limit['phase'] !== $phase->value) {
                continue;
            }
            foreach ($limit['targets'] as $target) {
                foreach ($schema->expand((string) $target) as $pointer) {
                    $value = $this->rating($sheet, $pointer, $schema);
                    if ($value > (int) $limit['max']) {
                        $issues[] = new ValidationIssue('limit.phase_max', Severity::Error, $touched[$pointer->toString()] ?? [], [$pointer->toString()], ['max' => (int) $limit['max'], 'phase' => $phase->value, 'rule' => $limit['id']]);
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $sheet
     * @param array<string, list<string>> $touched
     * @return list<ValidationIssue>
     */
    private function dependencies(array $sheet, array $touched): array
    {
        $issues = [];
        foreach ($this->rules['dependencies'] ?? [] as $dependency) {
            if (!self::isSet($sheet, JsonPointer::parse((string) $dependency['path']))) {
                continue;
            }
            foreach ($dependency['requires'] as $required) {
                if (!self::isSet($sheet, JsonPointer::parse((string) $required))) {
                    $paths = [(string) $dependency['path'], (string) $required];
                    $issues[] = new ValidationIssue('dependency.unmet', Severity::Error, $this->opsFor($paths, $touched), $paths, ['rule' => $dependency['id']]);
                }
            }
        }

        return $issues;
    }

    /** @return array<string, list<array<string, mixed>>> pointer => cost rules (in declaration order) */
    private function ratedTargets(SchemaNavigator $schema): array
    {
        $out = [];
        foreach ($this->rules['costs'] as $rule) {
            foreach ($rule['targets'] as $target) {
                foreach ($schema->expand((string) $target) as $pointer) {
                    $out[$pointer->toString()][] = $rule;
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $sheet */
    private function rating(array $sheet, JsonPointer $pointer, SchemaNavigator $schema): int
    {
        [$exists, $value] = SheetProjector::read($sheet, $pointer->segments);
        if ($exists && is_int($value)) {
            return $value;
        }
        $node = $schema->at($pointer) ?? [];

        return (int) ($node['default'] ?? $node['minimum'] ?? 0);
    }

    /** @param array<string, mixed> $sheet */
    private static function isSet(array $sheet, JsonPointer $pointer): bool
    {
        [$exists, $value] = SheetProjector::read($sheet, $pointer->segments);

        return $exists && $value !== null && $value !== '' && $value !== 0 && $value !== [] && $value !== false;
    }

    /** Layout bound (in addition to the schema's maxItems) for a precise operation-level error. */
    private function collectionMax(JsonPointer $path): ?int
    {
        return $this->layout->element($path)['max_items'] ?? null;
    }

    /**
     * @param list<string> $paths
     * @param array<string, list<string>> $touched
     * @return list<string>
     */
    private function opsFor(array $paths, array $touched): array
    {
        $ops = [];
        foreach ($paths as $path) {
            foreach ($touched as $touchedPath => $keys) {
                if ($path === $touchedPath || str_starts_with($path, $touchedPath . '/') || str_starts_with($touchedPath, $path . '/')) {
                    $ops = [...$ops, ...$keys];
                }
            }
        }

        return array_values(array_unique($ops));
    }

    /**
     * @param array<string, mixed> $doc
     * @param list<string> $segments
     */
    private static function remove(array &$doc, array $segments): void
    {
        $node = &$doc;
        foreach ($segments as $i => $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return;
            }
            if ($i === count($segments) - 1) {
                unset($node[$segment]);

                return;
            }
            $node = &$node[$segment];
        }
    }
}
