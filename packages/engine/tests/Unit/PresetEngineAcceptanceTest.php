<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Rules\Mechanics;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Tests\Support\DemoPreset;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 001 (preset engine) acceptance tests 1–4 at engine level, on the authorial demo presets.
 * API-level parts (concurrency, master approval, session lock, audit) live in apps/api tests.
 */
final class PresetEngineAcceptanceTest extends TestCase
{
    private const PRIORITIES = [
        ['id' => 'p1', 'type' => 'set_field', 'path' => '/priorities/physical', 'value' => 'primary'],
        ['id' => 'p2', 'type' => 'set_field', 'path' => '/priorities/social', 'value' => 'secondary'],
        ['id' => 'p3', 'type' => 'set_field', 'path' => '/priorities/mental', 'value' => 'tertiary'],
    ];

    /** A complete, valid creation for demo-linhagens. @return list<array<string, mixed>> */
    private static function completeCreation(): array
    {
        return [
            DemoPreset::set('n', '/identity/name', 'Iara'),
            DemoPreset::set('l', '/origin/lineage', 'aurora'),
            DemoPreset::set('g', '/origin/gift', 'voo_curto'),
            ...self::PRIORITIES,
            // physical 7: forca 1->3 (2), destreza 1->4 (3), vigor 1->3 (2)
            DemoPreset::set('a1', '/attributes/physical/forca', 3),
            DemoPreset::set('a2', '/attributes/physical/destreza', 4),
            DemoPreset::set('a3', '/attributes/physical/vigor', 3),
            // social 5: carisma 1->3 (2), manipulacao 1->2 (1), aparencia 1->3 (2)
            DemoPreset::set('a4', '/attributes/social/carisma', 3),
            DemoPreset::set('a5', '/attributes/social/manipulacao', 2),
            DemoPreset::set('a6', '/attributes/social/aparencia', 3),
            // mental 3: percepcao 1->2, inteligencia 1->2, raciocinio 1->2
            DemoPreset::set('a7', '/attributes/mental/percepcao', 2),
            DemoPreset::set('a8', '/attributes/mental/inteligencia', 2),
            DemoPreset::set('a9', '/attributes/mental/raciocinio', 2),
            // skills 6
            DemoPreset::set('s1', '/skills/luta', 3),
            DemoPreset::set('s2', '/skills/furtividade', 2),
            DemoPreset::set('s3', '/skills/oficio', 1),
        ];
    }

    // Test 1: creation by point pools ------------------------------------------------------------

    public function test_priority_categories_open_budgets_of_7_5_3_with_attributes_starting_at_1(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $outcome = $preset->evaluate([], self::PRIORITIES);

        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(['physical' => 7, 'social' => 5, 'mental' => 3, 'skill_points' => 6, 'xp' => 0], $outcome->balances);
        $scope = $preset->mechanics()->scope($outcome->sheet, []);
        $this->assertSame(1, $scope->pointer('/attributes/mental/raciocinio'), 'attributes start at 1 without spending');
    }

    public function test_allocating_above_a_category_budget_is_rejected_with_a_structured_error(): void
    {
        $outcome = (new DemoPreset('demo-linhagens'))->evaluate([], [
            ...self::PRIORITIES,
            DemoPreset::set('m1', '/attributes/mental/percepcao', 3),   // 2 points
            DemoPreset::set('m2', '/attributes/mental/inteligencia', 3), // 2 more: over 3
        ]);

        $this->assertSame(['budget.insufficient'], DemoPreset::ruleIds($outcome));
        $issue = $outcome->result->issues[0];
        $this->assertSame(['m2'], $issue->operationIds);
        $this->assertSame(['/attributes/mental/inteligencia'], $issue->paths);
        $this->assertSame(['budget' => 'mental', 'required' => 1, 'available' => 0, 'level' => 3], $issue->params);
    }

    public function test_priorities_must_be_distinct(): void
    {
        $outcome = (new DemoPreset('demo-linhagens'))->evaluate([], [
            ['id' => 'p1', 'type' => 'set_field', 'path' => '/priorities/physical', 'value' => 'primary'],
            ['id' => 'p2', 'type' => 'set_field', 'path' => '/priorities/social', 'value' => 'primary'],
            ['id' => 'p3', 'type' => 'set_field', 'path' => '/priorities/mental', 'value' => 'tertiary'],
        ]);

        $this->assertSame(['constraint.failed'], DemoPreset::ruleIds($outcome));
        $this->assertSame(['p1', 'p2', 'p3'], $outcome->result->issues[0]->operationIds);
    }

    public function test_a_derived_field_cannot_be_written_by_anyone_and_follows_its_source(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        foreach ([Actor::Player, Actor::Master] as $actor) {
            $outcome = $preset->evaluate([], [DemoPreset::set('x', '/derived/vitality', 99)], actor: $actor);
            $this->assertSame(['op.derived_read_only'], DemoPreset::ruleIds($outcome), $actor->value);
        }

        $base = $preset->evaluate([], [...self::PRIORITIES, DemoPreset::set('v', '/attributes/physical/vigor', 2)]);
        [$before] = $preset->mechanics()->materialize($base->sheet, []);
        $raised = $preset->evaluate($base->sheet, [DemoPreset::set('v', '/attributes/physical/vigor', 4)]);
        [$after, $trace] = $preset->mechanics()->materialize($raised->sheet, []);

        $this->assertSame(5, $before['derived']['vitality']);
        $this->assertSame(7, $after['derived']['vitality'], 'source change recomputes the dependent value');
        $this->assertArrayNotHasKey('derived', $raised->sheet, 'derived values are never stored as authority');
        $this->assertSame('vitality', $trace[0]['derived']);
    }

    // Test 2: creation by choices and steps ------------------------------------------------------

    public function test_choice_availability_follows_prerequisites(): void
    {
        $mechanics = (new DemoPreset('demo-linhagens'))->mechanics();
        $gift = static fn (array $sheet): array => array_column($mechanics->choiceAvailability($sheet, [])[1]['options'], 'available', 'value');

        $this->assertSame(['voo_curto' => false, 'pele_de_pedra' => false, 'sussurro' => true], $gift([]));
        $this->assertSame(['voo_curto' => true, 'pele_de_pedra' => false, 'sussurro' => true], $gift(['origin' => ['lineage' => 'aurora']]));
        $this->assertSame('aurora', $mechanics->choiceAvailability(['origin' => ['lineage' => 'aurora']], [])[0]['value']);
    }

    public function test_a_choice_allows_one_option_and_forbids_another_by_prerequisite(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $allowed = $preset->evaluate([], [DemoPreset::set('l', '/origin/lineage', 'aurora'), DemoPreset::set('g', '/origin/gift', 'voo_curto')]);
        $this->assertTrue($allowed->result->isValid());

        $forbidden = $preset->evaluate([], [DemoPreset::set('l', '/origin/lineage', 'nevoa'), DemoPreset::set('g', '/origin/gift', 'voo_curto')]);
        $this->assertSame(['choice.prerequisite_unmet'], DemoPreset::ruleIds($forbidden));
        $this->assertSame(['choice' => 'gift', 'option' => 'voo_curto'], $forbidden->result->issues[0]->params);
        $this->assertSame(['g'], $forbidden->result->issues[0]->operationIds);

        // Changing the prerequisite later is also caught: the whole candidate is validated.
        $later = $preset->evaluate($allowed->sheet, [DemoPreset::set('l2', '/origin/lineage', 'brasa')]);
        $this->assertSame(['choice.prerequisite_unmet'], DemoPreset::ruleIds($later));
    }

    public function test_creation_steps_run_identity_origin_attributes_review_and_incomplete_sheets_do_not_activate(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $empty = $preset->mechanics()->creationProgress([], []);
        $this->assertFalse($empty['complete']);
        $this->assertSame(['identity', 'origin', 'attributes', 'review'], array_column($empty['steps'], 'id'));
        $this->assertSame(['/identity/name'], $empty['steps'][0]['missing']);
        $this->assertFalse($empty['steps'][1]['available'], 'origin waits for identity');

        $partial = $preset->evaluate([], array_slice(self::completeCreation(), 0, 6));
        $progress = $preset->mechanics()->creationProgress($partial->sheet, []);
        $this->assertSame([true, true, false], array_slice(array_column($progress['steps'], 'complete'), 0, 3));
        $this->assertFalse($progress['complete'], 'unspent creation points keep the sheet incomplete');

        $full = $preset->evaluate([], self::completeCreation());
        $this->assertTrue($full->result->isValid(), json_encode($full->result->toArray()));
        $this->assertSame(['physical' => 0, 'social' => 0, 'mental' => 0, 'skill_points' => 0, 'xp' => 0], $full->balances);
        $this->assertTrue($preset->mechanics()->creationProgress($full->sheet, [])['complete']);
    }

    // Test 3: progression by purchase (engine part) ---------------------------------------------

    public function test_raising_a_trait_from_3_costs_current_times_4_and_insufficient_balance_blocks(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $created = $preset->evaluate([], self::completeCreation())->sheet;
        $raise = [DemoPreset::set('up', '/attributes/physical/forca', 4)];

        $short = $preset->evaluate($created, $raise, Phase::Advancement, ['xp' => 11]);
        $this->assertSame(['budget.insufficient'], DemoPreset::ruleIds($short));
        $this->assertSame(['budget' => 'xp', 'required' => 12, 'available' => 11, 'level' => 4], $short->result->issues[0]->params);
        $this->assertFalse($short->result->isValid(), 'the caller persists nothing for an invalid outcome');

        $paid = $preset->evaluate($created, $raise, Phase::Advancement, ['xp' => 15]);
        $this->assertTrue($paid->result->isValid());
        $this->assertSame(3, $paid->balances['xp']);
        $ledger = $paid->sheet['_ledger'];
        $entry = end($ledger);
        $this->assertSame(['path' => '/attributes/physical/forca', 'level' => 4, 'budget' => 'xp', 'cost' => 12, 'rule' => 'physical_xp'], $entry);
    }

    public function test_formula_cost_model_prices_a_new_skill_and_later_dots(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $created = $preset->evaluate([], self::completeCreation())->sheet;
        // erudicao 0 -> 1 costs 3 (formula `current == 0 ? 3 : current * 2`), 1 -> 2 costs 2.
        $outcome = $preset->evaluate($created, [DemoPreset::set('e', '/skills/erudicao', 2)], Phase::Advancement, ['xp' => 5]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(0, $outcome->balances['xp']);
    }

    // Test 4: progression by level / milestone ---------------------------------------------------

    public function test_xp_unlocks_levels_one_at_a_time_and_levels_unlock_benefits(): void
    {
        $preset = new DemoPreset('demo-heroico');
        $sheet = ['identity' => ['name' => 'Tauã']];

        $notEarned = $preset->evaluate($sheet, [DemoPreset::set('l', '/progress/level', 2)], Phase::Advancement, ['xp' => 299]);
        $this->assertSame(['progression.level_not_earned'], DemoPreset::ruleIds($notEarned));
        $this->assertSame(['track' => 'level', 'earned' => 1, 'requested' => 2], $notEarned->result->issues[0]->params);

        $skip = $preset->evaluate($sheet, [DemoPreset::set('l', '/progress/level', 3)], Phase::Advancement, ['xp' => 900]);
        $this->assertSame(['progression.level_skip'], DemoPreset::ruleIds($skip));

        $two = $preset->evaluate($sheet, [DemoPreset::set('l', '/progress/level', 2)], Phase::Advancement, ['xp' => 900]);
        $three = $preset->evaluate($two->sheet, [DemoPreset::set('l', '/progress/level', 3)], Phase::Advancement, ['xp' => 900]);
        $this->assertTrue($three->result->isValid());
        $this->assertSame(1, $three->balances['talent_points'], 'level 3 unlocks a talent point');

        $talent = $preset->evaluate($three->sheet, [DemoPreset::set('t', '/talents/golpe_firme', 1)], Phase::Advancement, ['xp' => 900]);
        $this->assertTrue($talent->result->isValid());
        $this->assertSame(0, $talent->balances['talent_points']);

        [$view] = $preset->mechanics()->materialize($three->sheet, ['xp' => 900]);
        $this->assertSame(2, $view['progress']['proficiency']);
        $this->assertSame(15, $view['progress']['max_hp'], 'level 3 x (6 + mod(8) = -1)');
    }

    public function test_milestones_can_replace_experience_on_the_same_track(): void
    {
        $preset = new DemoPreset('demo-heroico');
        $outcome = $preset->evaluate([], [DemoPreset::set('l', '/progress/level', 2)], Phase::Advancement, ['milestones' => 1]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
    }

    public function test_a_specialization_requires_its_vocation_and_level_3(): void
    {
        $preset = new DemoPreset('demo-heroico');
        $early = $preset->evaluate([], [DemoPreset::set('v', '/calling/vocation', 'lamina'), DemoPreset::set('s', '/calling/specialization', 'baluarte')]);
        $this->assertSame(['choice.prerequisite_unmet'], DemoPreset::ruleIds($early));

        $sheet = ['calling' => ['vocation' => 'lamina'], 'progress' => ['level' => 3]];
        $ok = $preset->evaluate($sheet, [DemoPreset::set('s', '/calling/specialization', 'baluarte')], Phase::Advancement, ['xp' => 900]);
        $this->assertTrue($ok->result->isValid());
        [$view, $trace] = $preset->mechanics()->materialize($ok->sheet, ['xp' => 900]);
        $this->assertSame(15 + 3, $view['progress']['max_hp'], 'the specialization effect adds the level to max HP');
        $hp = array_values(array_filter($trace, static fn (array $e): bool => $e['derived'] === 'max_hp'))[0];
        $this->assertSame(['effect' => 'bulwark_hp', 'mode' => 'add', 'value' => 3, 'source' => ['kind' => 'rule'], 'applied' => true], $hp['modifiers'][0]);
    }

    public function test_a_newer_rules_revision_does_not_change_a_character_pinned_to_the_old_one(): void
    {
        $old = new DemoPreset('demo-heroico');
        $rules = $old->parts['rules'];
        $rules['progression']['tracks'][0]['thresholds'][1] = 500; // revision: level 2 now needs 500 XP
        $new = new DemoPreset('demo-heroico', $rules);
        $sheet = ['progress' => ['level' => 1]];
        $op = [DemoPreset::set('l', '/progress/level', 2)];

        $this->assertTrue($old->evaluate($sheet, $op, Phase::Advancement, ['xp' => 300])->result->isValid());
        $this->assertSame(['progression.level_not_earned'], DemoPreset::ruleIds($new->evaluate($sheet, $op, Phase::Advancement, ['xp' => 300])));
        // Evaluating the newer rules leaves no state behind: the pinned rules still answer the same.
        $this->assertTrue($old->evaluate($sheet, $op, Phase::Advancement, ['xp' => 300])->result->isValid());
    }

    // Effects, resources and inventory -----------------------------------------------------------

    public function test_effects_stack_by_group_and_report_their_source(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $sheet = [
            'origin' => ['lineage' => 'nevoa', 'gift' => 'sussurro'],
            'attributes' => ['physical' => ['destreza' => 3], 'mental' => ['raciocinio' => 2]],
            'inventory' => [
                ['id' => 'amuleto', 'name' => 'Amuleto', 'equipped' => true, 'initiative_bonus' => 1],
                ['id' => 'anel', 'name' => 'Anel', 'equipped' => true, 'initiative_bonus' => 2],
                ['id' => 'pena', 'name' => 'Pena', 'equipped' => false, 'initiative_bonus' => 3],
            ],
        ];
        [$view, $trace] = $preset->mechanics()->materialize($sheet, []);
        // 3 + 2, highest equipped trinket (+2, not +1+2, not the unequipped +3), gift +1.
        $this->assertSame(8, $view['derived']['initiative']);
        $initiative = array_values(array_filter($trace, static fn (array $e): bool => $e['derived'] === 'initiative'))[0];
        $applied = array_map(static fn (array $m): string => ($m['source']['item_id'] ?? $m['effect']) . ':' . ($m['applied'] ? 'y' : 'n'), $initiative['modifiers']);
        $this->assertSame(['amuleto:n', 'anel:y', 'mist_initiative:y'], $applied);
    }

    public function test_resources_stay_within_their_derived_maximum_and_inventory_within_capacity(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $over = $preset->evaluate(['attributes' => ['physical' => ['vigor' => 2]]], [DemoPreset::set('h', '/resources/vitality_current', 6)], Phase::Advancement);
        $this->assertSame(['resource.out_of_range'], DemoPreset::ruleIds($over));
        $this->assertSame(['resource' => 'vitality', 'min' => 0, 'max' => 5], $over->result->issues[0]->params);

        $heavy = $preset->evaluate([], [['id' => 'i', 'type' => 'add_item', 'path' => '/inventory', 'item_id' => 'bau', 'value' => ['name' => 'Baú', 'weight' => 6, 'quantity' => 1]]], Phase::Advancement, actor: Actor::Master);
        $this->assertSame(['inventory.over_capacity'], DemoPreset::ruleIds($heavy));
    }

    public function test_two_presets_with_different_taxonomies_share_the_core_without_names_in_it(): void
    {
        $linhagens = (new DemoPreset('demo-linhagens'))->parts['compendium'];
        $heroico = (new DemoPreset('demo-heroico'))->parts['compendium'];
        $this->assertSame(['lineage', 'gift'], array_column($linhagens['taxonomy'], 'kind'));
        $this->assertSame(['vocation', 'specialization'], array_column($heroico['taxonomy'], 'kind'));

        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../src')) as $file) {
            if ($file->getExtension() === 'php') {
                $source .= strtolower((string) file_get_contents($file->getPathname()));
            }
        }
        foreach (['dnd', 'd&d', 'vampir', 'storyteller', 'linhagens', 'heroico', 'lineage', 'vocation', 'clan', 'discipline', 'sangue-suga'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "engine core must not know '{$needle}'");
        }
    }

    public function test_mechanics_from_two_presets_are_isolated_in_one_process(): void
    {
        $a = (new DemoPreset('demo-linhagens'))->mechanics();
        $b = (new DemoPreset('demo-heroico'))->mechanics();
        $this->assertNotNull($a->derivedAt('/derived/vitality'));
        $this->assertNull($b->derivedAt('/derived/vitality'));
        $this->assertNotNull($b->derivedAt('/progress/proficiency'));
        $this->assertInstanceOf(Mechanics::class, new Mechanics(['budgets' => [], 'costs' => []], new SchemaNavigator(['type' => 'object'])));
    }
}
