<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Tests\Support\DemoPreset;
use PHPUnit\Framework\TestCase;

/** Cost rules that price only some increments, losses without refund, and pool count modifiers. */
final class CostRuleOptionsTest extends TestCase
{
    private const PRIORITIES = [
        ['id' => 'p1', 'type' => 'set_field', 'path' => '/priorities/physical', 'value' => 'primary'],
        ['id' => 'p2', 'type' => 'set_field', 'path' => '/priorities/social', 'value' => 'secondary'],
        ['id' => 'p3', 'type' => 'set_field', 'path' => '/priorities/mental', 'value' => 'tertiary'],
    ];

    /** Skill points pay only up to 2; above that the next rule (xp, at creation for the test) pays. */
    private static function preset(int $freebies = 5): DemoPreset
    {
        $preset = new DemoPreset('demo-linhagens');
        $rules = &$preset->parts['rules'];
        foreach ($rules['costs'] as $i => $cost) {
            if ($cost['id'] === 'skill_creation') {
                $rules['costs'][$i]['applies_when'] = ['op' => 'compare', 'cmp' => 'le', 'args' => [['var' => 'new'], ['const' => 2]]];
            }
        }
        $rules['budgets'][] = ['id' => 'freebies', 'kind' => 'initial', 'label' => 'Bônus', 'initial_amount' => $freebies];
        $rules['costs'][] = ['id' => 'skill_freebie', 'phase' => 'creation', 'budget' => 'freebies', 'targets' => ['/skills/*'], 'model' => ['type' => 'fixed_per_dot', 'amount' => 2]];
        foreach ($rules['costs'] as $i => $cost) {
            if ($cost['id'] === 'mental_xp') {
                $rules['costs'][$i]['refund_on_decrease'] = false;
            }
        }

        return $preset;
    }

    public function test_a_rule_prices_only_the_increments_it_applies_to_and_the_next_rule_pays_the_rest(): void
    {
        $outcome = self::preset()->evaluate([], [...self::PRIORITIES, DemoPreset::set('s', '/skills/luta', 3)]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(4, $outcome->balances['skill_points'], 'levels 1 and 2 from skill points');
        $this->assertSame(3, $outcome->balances['freebies'], 'level 3 from freebies (2)');
    }

    public function test_an_unaffordable_increment_reports_the_real_price_of_the_rule_that_applies(): void
    {
        $outcome = self::preset(1)->evaluate([], [...self::PRIORITIES, DemoPreset::set('s', '/skills/luta', 3)]);
        $this->assertSame(['budget.insufficient'], DemoPreset::ruleIds($outcome));
        $this->assertSame(['budget' => 'freebies', 'required' => 2, 'available' => 1, 'level' => 3], $outcome->result->issues[0]->params);
    }

    public function test_a_loss_removes_paid_levels_without_refunding_them(): void
    {
        $preset = self::preset();
        $raised = $preset->evaluate(['attributes' => ['mental' => ['percepcao' => 1]]], [DemoPreset::set('up', '/attributes/mental/percepcao', 2)], Phase::Advancement, ['xp' => 5]);
        $this->assertTrue($raised->result->isValid(), json_encode($raised->result->toArray()));
        $this->assertSame(1, $raised->balances['xp'], 'current 1 × 4');
        $lost = $preset->evaluate($raised->sheet, [DemoPreset::set('down', '/attributes/mental/percepcao', 1)], Phase::Advancement, ['xp' => 5]);
        $this->assertTrue($lost->result->isValid(), json_encode($lost->result->toArray()));
        $this->assertSame(1, $lost->balances['xp'], 'no XP back for a loss');
        $this->assertTrue($lost->sheet['_ledger'][0]['lost'], 'the loss stays on the ledger');
        $again = $preset->evaluate($lost->sheet, [DemoPreset::set('up2', '/attributes/mental/percepcao', 2)], Phase::Advancement, ['xp' => 9]);
        $this->assertSame(1, $again->balances['xp'], 'buying the level again costs again (9 - 4 lost - 4)');
    }

    public function test_a_pool_count_modifier_is_applied_on_the_server(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $rules = $preset->parts['rules'];
        foreach ($rules['rolls'] as $i => $roll) {
            if ($roll['strategy'] === 'pool') {
                $rules['rolls'][$i]['count_modifier'] = ['const' => -2];
                $id = $roll['id'];
                break;
            }
        }
        $scope = $preset->mechanics()->scope(['attributes' => ['physical' => ['forca' => 3, 'destreza' => 3]], 'skills' => ['luta' => 2]], []);
        $declared = (new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema'])))->prepare($id, $scope, ['inputs' => self::inputs($preset, $id)]);
        $modified = (new PresetRolls($rules, new SchemaNavigator($preset->parts['sheet_schema'])))->prepare($id, $scope, ['inputs' => self::inputs($preset, $id)]);
        $this->assertSame(max(0, $declared->count - 2), $modified->count);
    }

    /** @return array<string, string> first allowed target for every input of a roll */
    private static function inputs(DemoPreset $preset, string $id): array
    {
        $navigator = new SchemaNavigator($preset->parts['sheet_schema']);
        $out = [];
        foreach ($preset->parts['rules']['rolls'] as $roll) {
            if ($roll['id'] !== $id) {
                continue;
            }
            foreach ($roll['inputs'] ?? [] as $name => $input) {
                $out[$name] = $navigator->expand((string) $input['targets'][0])[0]->toString();
            }
        }

        return $out;
    }
}
