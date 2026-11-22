<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\ChangeOperation;
use Novtt\Engine\Sheets\ChangeOutcome;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Sheets\SheetChangeEngine;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class SheetChangeEngineTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0';

    /** @var list<array<string, mixed>> documents seen by the structural port */
    private array $validated = [];

    /** @return array<string, mixed> */
    private static function load(string $file): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE . '/' . $file), true, 64, JSON_THROW_ON_ERROR);
    }

    private function engine(?ValidationResult $structural = null): SheetChangeEngine
    {
        $test = $this;
        $port = new class ($structural ?? ValidationResult::ok(), $test) implements StructuralValidator {
            public function __construct(private ValidationResult $result, private SheetChangeEngineTest $test)
            {
            }

            public function validate(mixed $document, array|object $schema): ValidationResult
            {
                $this->test->recordValidated($document);

                return $this->result;
            }

            public function validateContract(mixed $document, string $contract): ValidationResult
            {
                return ValidationResult::ok();
            }
        };
        $schema = self::load('sheet.schema.json');

        return new SheetChangeEngine(SheetLayout::fromArray(self::load('sheet.ui.json')), $schema, $schema, self::load('rules.json'), $port);
    }

    public function recordValidated(mixed $document): void
    {
        $this->validated[] = $document;
    }

    /** @param list<array<string, mixed>> $ops */
    private function evaluate(array $sheet, array $ops, Actor $actor = Actor::Player, Phase $phase = Phase::Creation, array $grants = [], ?ValidationResult $structural = null): ChangeOutcome
    {
        return $this->engine($structural)->evaluate($sheet, array_map(ChangeOperation::fromArray(...), $ops), $actor, $phase, $grants);
    }

    /** @return list<string> */
    private static function rules(ChangeOutcome $outcome): array
    {
        return array_map(static fn (ValidationIssue $i): string => $i->ruleId, $outcome->result->issues);
    }

    public function test_creation_spends_initial_budget_then_bonus_with_provenance(): void
    {
        // might 1 -> 4: level 1 is a free dot; levels 2..4 cost 1 each from attribute_points (4).
        // agility 1 -> 3: level 2 costs the last attribute point; level 3 falls through to bonus (5).
        $outcome = $this->evaluate([], [
            ['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 4],
            ['id' => 'b', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 3],
        ]);

        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(['attribute_points' => 0, 'skill_points' => 3, 'bonus' => 0, 'xp' => 0], $outcome->balances);
        $budgets = array_map(static fn (array $e): string => "{$e['path']}#{$e['level']}={$e['budget']}:{$e['cost']}", $outcome->sheet['_ledger']);
        $this->assertSame([
            '/attributes/might#2=attribute_points:1', '/attributes/might#3=attribute_points:1', '/attributes/might#4=attribute_points:1',
            '/attributes/agility#2=attribute_points:1', '/attributes/agility#3=bonus:5',
        ], $budgets);
    }

    public function test_lowering_a_rating_refunds_the_budget_that_paid_it(): void
    {
        $first = $this->evaluate([], [
            ['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 4],
            ['id' => 'b', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 3],
        ]);
        // Lower agility 3 -> 2: refunds 5 bonus (not attribute points); then wits 1 -> 2 uses bonus.
        $second = $this->evaluate($first->sheet, [
            ['id' => 'c', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 2],
            ['id' => 'd', 'type' => 'set_field', 'path' => '/attributes/wits', 'value' => 2],
        ]);

        $this->assertTrue($second->result->isValid());
        $this->assertSame(0, $second->balances['bonus']);
        $this->assertSame(0, $second->balances['attribute_points']);
        $ledger = $second->sheet['_ledger'];
        $this->assertSame('bonus', end($ledger)['budget']);
    }

    public function test_insufficient_budget_reports_budget_required_and_available(): void
    {
        $outcome = $this->evaluate([], [['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 5], ['id' => 'b', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 3]]);

        $this->assertFalse($outcome->result->isValid());
        $issue = $outcome->result->issues[0];
        $this->assertSame('budget.insufficient', $issue->ruleId);
        $this->assertSame(['b'], $issue->operationIds);
        $this->assertSame(['/attributes/agility'], $issue->paths);
        $this->assertSame(['budget' => 'attribute_points', 'required' => 1, 'available' => 0, 'level' => 3], $issue->params);
    }

    public function test_advancement_uses_experience_and_grants_never_spend_by_themselves(): void
    {
        $sheet = ['attributes' => ['might' => 2]];
        $noXp = $this->evaluate($sheet, [['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 3]], phase: Phase::Advancement);
        $this->assertSame(['budget.insufficient'], self::rules($noXp));

        $granted = $this->evaluate($sheet, [], phase: Phase::Advancement, grants: ['xp' => 10]);
        $this->assertSame(10, $granted->balances['xp'], 'a grant alone changes nothing on the sheet');
        $this->assertSame($sheet, $granted->sheet);

        // current_rating_multiplier 4: 2 -> 3 costs 8 XP.
        $spent = $this->evaluate($sheet, [['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 3]], phase: Phase::Advancement, grants: ['xp' => 10]);
        $this->assertTrue($spent->result->isValid());
        $this->assertSame(2, $spent->balances['xp']);
        // when_zero: skill 0 -> 1 costs 3 XP.
        $skill = $this->evaluate([], [['id' => 's', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 1]], phase: Phase::Advancement, grants: ['xp' => 3]);
        $this->assertSame(0, $skill->balances['xp']);
    }

    public function test_phase_limits_and_dependencies(): void
    {
        $capped = $this->evaluate([], [['id' => 's', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 4]], grants: ['skill_points' => 10]);
        $this->assertContains('limit.phase_max', self::rules($capped));
        $this->assertTrue($this->evaluate(['skills' => ['climb' => 3]], [['id' => 's', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 4]], phase: Phase::Advancement, grants: ['xp' => 6])->result->isValid(), 'creation cap does not apply in advancement');

        $unmet = $this->evaluate(['attributes' => ['wits' => 0]], [['id' => 'l', 'type' => 'set_field', 'path' => '/skills/lore', 'value' => 1]]);
        $this->assertContains('dependency.unmet', self::rules($unmet));
    }

    public function test_players_cannot_touch_hidden_master_only_undeclared_or_reserved_paths_and_learn_nothing(): void
    {
        foreach (['/secrets/hook', '/master/notes', '/identity/unknown', '/attributes'] as $path) {
            $outcome = $this->evaluate([], [['id' => 'x', 'type' => 'set_field', 'path' => $path, 'value' => 'v']]);
            $this->assertSame(['op.path_not_allowed'], self::rules($outcome), $path);
            $this->assertSame([], $outcome->result->issues[0]->paths, 'no path echoed back to players');
        }
        $this->assertTrue($this->evaluate([], [['id' => 'x', 'type' => 'set_field', 'path' => '/master/notes', 'value' => 'ok']], Actor::Master)->result->isValid());
        $ledger = ChangeOperation::fromArray(['id' => 'x', 'type' => 'unset_field', 'path' => '/attributes/might']);
        $this->assertTrue($this->engine()->evaluate([], [$ledger], Actor::Player, Phase::Creation)->result->isValid());
    }

    public function test_collection_operations_with_stable_ids_and_preconditions(): void
    {
        $sheet = ['merits' => [['id' => 'm1', 'name' => 'A', 'cost' => 1], ['id' => 'm2', 'name' => 'B', 'cost' => 2]]];
        $outcome = $this->evaluate($sheet, [
            ['id' => 'o1', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm3', 'value' => ['name' => 'C', 'cost' => 3]],
            ['id' => 'o2', 'type' => 'set_item_field', 'path' => '/merits', 'item_id' => 'm1', 'field' => 'cost', 'value' => 2, 'expected' => 1],
            ['id' => 'o3', 'type' => 'remove_item', 'path' => '/merits', 'item_id' => 'm2'],
            ['id' => 'o4', 'type' => 'reorder_items', 'path' => '/merits', 'order' => ['m3', 'm1']],
        ]);
        $this->assertTrue($outcome->result->isValid());
        $this->assertSame([['id' => 'm3', 'name' => 'C', 'cost' => 3], ['id' => 'm1', 'name' => 'A', 'cost' => 2]], $outcome->sheet['merits']);
        $this->assertSame(['id' => 'm1', 'name' => 'A', 'cost' => 1], $sheet['merits'][0], 'input is never mutated');

        $this->assertSame(['op.item_exists'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm1', 'value' => ['name' => 'X', 'cost' => 1]]])));
        $this->assertSame(['op.item_not_found'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'remove_item', 'path' => '/merits', 'item_id' => 'zz']])));
        $this->assertSame(['op.order_mismatch'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'reorder_items', 'path' => '/merits', 'order' => ['m1']]])));
        $this->assertSame(['op.precondition_failed'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'set_item_field', 'path' => '/merits', 'item_id' => 'm1', 'field' => 'cost', 'value' => 5, 'expected' => 7]])));
        $this->assertSame(['op.path_not_allowed'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'set_item_field', 'path' => '/merits', 'item_id' => 'm1', 'field' => 'smuggled', 'value' => 1]])));
        $this->assertSame(['op.path_not_allowed'], self::rules($this->evaluate($sheet, [['id' => 'o', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm9', 'value' => ['name' => 'X', 'cost' => 1, 'secret' => 1]]])));
    }

    public function test_the_whole_candidate_is_validated_without_the_engine_ledger(): void
    {
        $outcome = $this->evaluate([], [['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 2]],
            structural: new ValidationResult([new ValidationIssue('schema.maximum', paths: ['/attributes/might'])]));

        $this->assertSame(['schema.maximum'], self::rules($outcome));
        $this->assertSame(['a'], $outcome->result->issues[0]->operationIds);
        $validated = $this->validated;
        $this->assertArrayNotHasKey('_ledger', end($validated));
    }

    public function test_precondition_on_scalar_detects_concurrent_change(): void
    {
        $outcome = $this->evaluate(['identity' => ['name' => 'Ana']], [['id' => 'n', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Bia', 'expected' => 'Ann']]);
        $this->assertSame(['op.precondition_failed'], self::rules($outcome));
    }
}
