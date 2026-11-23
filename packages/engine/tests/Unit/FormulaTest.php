<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Formula\FormulaChecker;
use Novtt\Engine\Formula\FormulaContext;
use Novtt\Engine\Formula\FormulaEvaluator;
use Novtt\Engine\Formula\FormulaException;
use Novtt\Engine\Formula\FormulaLimits;
use Novtt\Engine\Formula\Scope;
use Novtt\Engine\Formula\StepMeter;
use Novtt\Engine\Validation\ValidationIssue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormulaTest extends TestCase
{
    /** @param array<string, mixed> $values @param array<string, list<array<string, mixed>>> $collections */
    private static function scope(array $values = [], array $collections = [], ?StepMeter $meter = null): Scope
    {
        return new class ($values, $collections, $meter ?? new StepMeter()) implements Scope {
            public function __construct(private array $values, private array $collections, private StepMeter $meter)
            {
            }

            public function pointer(string $pointer): mixed
            {
                return $this->values[$pointer] ?? 0;
            }

            public function derived(string $id): mixed
            {
                return $this->values['derived:' . $id] ?? 0;
            }

            public function budget(string $id, string $measure): int
            {
                return (int) ($this->values["budget:{$id}:{$measure}"] ?? 0);
            }

            public function variable(string $name): mixed
            {
                return $this->values['var:' . $name] ?? throw new FormulaException('formula.unknown_variable');
            }

            public function collection(string $pointer): array
            {
                return $this->collections[$pointer] ?? [];
            }

            public function meter(): StepMeter
            {
                return $this->meter;
            }
        };
    }

    private static function evaluate(mixed $node, array $values = [], array $collections = []): mixed
    {
        return (new FormulaEvaluator(self::scope($values, $collections)))->evaluate($node);
    }

    public function test_arithmetic_floor_min_max_and_exact_integer_division(): void
    {
        $node = ['op' => 'floor', 'args' => [['op' => 'divide', 'args' => [
            ['op' => 'subtract', 'args' => [['ref' => '/abilities/forca'], ['const' => 10]]], ['const' => 2],
        ]]]];
        $this->assertSame(2, self::evaluate($node, ['/abilities/forca' => 15]));
        $this->assertSame(-1, self::evaluate($node, ['/abilities/forca' => 9]));
        $this->assertSame(3, self::evaluate(['op' => 'divide', 'args' => [['const' => 6], ['const' => 2]]]));
        $this->assertSame(7, self::evaluate(['op' => 'max', 'args' => [['const' => 3], ['const' => 7], ['const' => -2]]]));
        $this->assertSame(-2, self::evaluate(['op' => 'min', 'args' => [['const' => 3], ['const' => 7], ['const' => -2]]]));
    }

    public function test_lookup_by_exact_key_and_by_ascending_steps(): void
    {
        $table = ['op' => 'lookup', 'key' => ['ref' => '/p'], 'table' => ['primary' => 7, 'secondary' => 5], 'default' => ['const' => 0]];
        $this->assertSame(7, self::evaluate($table, ['/p' => 'primary']));
        $this->assertSame(0, self::evaluate($table, ['/p' => 'unknown']));

        $steps = ['op' => 'lookup', 'key' => ['ref' => '/xp'], 'steps' => [[0, 1], [300, 2], [900, 3]]];
        $this->assertSame(1, self::evaluate($steps, ['/xp' => 299]));
        $this->assertSame(2, self::evaluate($steps, ['/xp' => 300]));
        $this->assertSame(3, self::evaluate($steps, ['/xp' => 100000]));
        $this->expectExceptionObject(new FormulaException('formula.lookup_missing'));
        self::evaluate($steps, ['/xp' => -1]);
    }

    public function test_sum_and_count_over_a_collection_with_a_filter(): void
    {
        $items = ['/inventory' => [
            ['id' => 'a', 'weight' => 2, 'quantity' => 3, 'equipped' => true],
            ['id' => 'b', 'weight' => 5, 'quantity' => 1, 'equipped' => false],
        ]];
        $load = ['op' => 'sum', 'collection' => '/inventory', 'value' => ['op' => 'multiply', 'args' => [['item' => 'weight'], ['item' => 'quantity']]]];
        $this->assertSame(11, self::evaluate($load, [], $items));
        $this->assertSame(1, self::evaluate(['op' => 'count', 'collection' => '/inventory', 'where' => ['item' => 'equipped']], [], $items));
    }

    public function test_comparison_is_strict_across_types_and_if_requires_a_boolean(): void
    {
        $this->assertFalse(self::evaluate(['op' => 'compare', 'cmp' => 'eq', 'args' => [['const' => '1'], ['const' => 1]]]));
        $this->assertTrue(self::evaluate(['op' => 'compare', 'cmp' => 'eq', 'args' => [['const' => 2], ['const' => 2.0]]]));
        $this->expectExceptionObject(new FormulaException('formula.type_mismatch'));
        self::evaluate(['op' => 'if', 'args' => [['const' => 1], ['const' => 'a'], ['const' => 'b']]]);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function runtimeFailures(): iterable
    {
        yield 'division by zero from data' => [['op' => 'divide', 'args' => [['const' => 1], ['ref' => '/zero']]], 'formula.division_by_zero'];
        yield 'number expected' => [['op' => 'add', 'args' => [['const' => 1], ['const' => 'x']]], 'formula.type_mismatch'];
        yield 'overflow is rejected, not wrapped' => [['op' => 'multiply', 'args' => [['const' => 1_000_000_000], ['const' => 10]]], 'formula.out_of_bounds'];
        yield 'unknown variable' => [['var' => 'nope'], 'formula.unknown_variable'];
    }

    #[DataProvider('runtimeFailures')]
    public function test_runtime_failures_are_structured(array $node, string $rule): void
    {
        try {
            self::evaluate($node, ['/zero' => 0]);
            $this->fail('expected ' . $rule);
        } catch (FormulaException $e) {
            $this->assertSame($rule, $e->ruleId);
        }
    }

    public function test_a_shared_step_budget_bounds_the_cost_of_one_evaluation(): void
    {
        $items = ['/many' => array_map(static fn (int $i): array => ['id' => "i{$i}", 'v' => 1], range(1, 500))];
        $meter = new StepMeter(300);
        $evaluator = new FormulaEvaluator(self::scope([], $items, $meter));
        try {
            $evaluator->evaluate(['op' => 'sum', 'collection' => '/many', 'value' => ['item' => 'v']]);
            $this->fail('budget not enforced');
        } catch (FormulaException $e) {
            $this->assertSame('formula.evaluation_budget_exceeded', $e->ruleId);
            $this->assertLessThanOrEqual(301, $meter->used());
        }
    }

    /** @return iterable<string, array{0: mixed, 1: string, 2?: FormulaContext}> */
    public static function staticRejections(): iterable
    {
        yield 'operator outside the allow-list' => [['op' => 'eval', 'args' => [['const' => 'phpinfo()']]], 'UNSUPPORTED_CAPABILITY'];
        yield 'unknown key smuggled into a node' => [['op' => 'add', 'args' => [['const' => 1], ['const' => 2]], 'code' => 'x'], 'formula.unknown_key'];
        yield 'list instead of node' => [[1, 2], 'formula.invalid_node'];
        yield 'wrong arity' => [['op' => 'divide', 'args' => [['const' => 1]]], 'formula.arity'];
        yield 'literal division by zero' => [['op' => 'divide', 'args' => [['const' => 1], ['const' => 0]]], 'formula.division_by_zero'];
        yield 'item outside sum/count' => [['item' => 'weight'], 'formula.item_outside_collection'];
        yield 'reserved engine data' => [['ref' => '/_ledger'], 'formula.reserved_reference'];
        yield 'undeclared variable' => [['var' => 'current'], 'formula.unknown_variable'];
        yield 'budget read where budgets are not allowed' => [['budget' => 'xp', 'of' => 'balance'], 'formula.reference_not_allowed', new FormulaContext(budgets: false)];
        yield 'steps must ascend' => [['op' => 'lookup', 'key' => ['const' => 1], 'steps' => [[5, 1], [3, 2]]], 'formula.steps_not_ascending'];
    }

    #[DataProvider('staticRejections')]
    public function test_checker_rejects_unsafe_or_malformed_formulas(mixed $node, string $rule, ?FormulaContext $context = null): void
    {
        [$issues] = FormulaChecker::check($node, $context ?? new FormulaContext(), '/rules/derived/0/formula');
        $this->assertContains($rule, array_map(static fn (ValidationIssue $i): string => $i->ruleId, $issues));
    }

    public function test_checker_bounds_depth_and_size(): void
    {
        $deep = ['const' => 1];
        for ($i = 0; $i < FormulaLimits::MAX_DEPTH + 2; $i++) {
            $deep = ['op' => 'not', 'args' => [$deep]];
        }
        [$issues] = FormulaChecker::check($deep, new FormulaContext(), '/f');
        $this->assertContains('formula.too_deep', array_map(static fn (ValidationIssue $i): string => $i->ruleId, $issues));

        $wide = ['op' => 'add', 'args' => array_fill(0, 32, ['op' => 'add', 'args' => array_fill(0, 32, ['const' => 1])])];
        [$issues] = FormulaChecker::check($wide, new FormulaContext(), '/f');
        $this->assertContains('formula.too_complex', array_map(static fn (ValidationIssue $i): string => $i->ruleId, $issues));
    }

    public function test_checker_collects_references_for_dependency_analysis(): void
    {
        [$issues, $refs] = FormulaChecker::check(['op' => 'add', 'args' => [
            ['ref' => '/a'], ['derived' => 'mod'], ['budget' => 'xp', 'of' => 'granted'], ['var' => 'current'],
            ['op' => 'count', 'collection' => '/items', 'where' => ['item' => 'equipped']],
        ]], new FormulaContext(variables: ['current']), '/f');
        $this->assertSame([], $issues);
        $this->assertSame(['/a'], array_keys($refs->pointers));
        $this->assertSame(['mod'], array_keys($refs->derived));
        $this->assertSame(['xp'], array_keys($refs->budgets));
        $this->assertSame(['/items'], array_keys($refs->collections));
        $this->assertSame(['equipped'], array_keys($refs->itemFields));
    }
}
