<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Resolution\RollRejected;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Tests\Support\SequenceRandom;
use PHPUnit\Framework\TestCase;

/**
 * Generic capabilities for faithful presets: an option that holds a field (a lineage that sets a
 * trait to 0 and locks it) and a roll that keeps the best or worst of N dice.
 */
final class ChoiceFixesAndKeepRollTest extends TestCase
{
    private const PRIORITIES = [
        ['id' => 'p1', 'type' => 'set_field', 'path' => '/priorities/physical', 'value' => 'primary'],
        ['id' => 'p2', 'type' => 'set_field', 'path' => '/priorities/social', 'value' => 'secondary'],
        ['id' => 'p3', 'type' => 'set_field', 'path' => '/priorities/mental', 'value' => 'tertiary'],
    ];

    /** demo-linhagens where the "brasa" lineage holds Aparência at 0 (authorial test content). */
    private static function preset(): DemoPreset
    {
        $preset = new DemoPreset('demo-linhagens');
        $preset->parts['sheet_schema']['properties']['attributes']['properties']['social']['properties']['aparencia']['minimum'] = 0;
        foreach ($preset->parts['rules']['creation']['choices'] as $c => $choice) {
            foreach ($choice['options'] as $o => $option) {
                if ($option['value'] === 'brasa') {
                    $preset->parts['rules']['creation']['choices'][$c]['options'][$o]['fixes'] = [
                        ['path' => '/attributes/social/aparencia', 'value' => ['const' => 0], 'label' => 'Marcas da brasa'],
                    ];
                }
            }
        }

        return $preset;
    }

    public function test_choosing_the_option_writes_the_fixed_value_and_refunds_points_spent_there(): void
    {
        $preset = self::preset();
        $before = $preset->evaluate([], [...self::PRIORITIES, DemoPreset::set('a', '/attributes/social/aparencia', 3)]);
        $this->assertTrue($before->result->isValid(), json_encode($before->result->toArray()));
        $this->assertSame(3, $before->balances['social']);

        $after = $preset->evaluate($before->sheet, [DemoPreset::set('l', '/origin/lineage', 'brasa')]);
        $this->assertTrue($after->result->isValid(), json_encode($after->result->toArray()));
        $this->assertSame(0, $after->sheet['attributes']['social']['aparencia']);
        $this->assertSame(5, $after->balances['social'], 'the 2 points spent on Aparência come back; the free dot is not paid out');
    }

    public function test_the_fixed_field_cannot_be_raised_while_the_option_holds_it(): void
    {
        $preset = self::preset();
        $chosen = $preset->evaluate([], [...self::PRIORITIES, DemoPreset::set('l', '/origin/lineage', 'brasa')]);
        $raise = $preset->evaluate($chosen->sheet, [DemoPreset::set('x', '/attributes/social/aparencia', 2)]);

        $this->assertSame(['choice.fixed_value'], DemoPreset::ruleIds($raise));
        $this->assertSame(['x'], $raise->result->issues[0]->operationIds);
        $this->assertSame(['choice' => 'lineage', 'option' => 'brasa', 'value' => 0], $raise->result->issues[0]->params);
    }

    public function test_availability_reports_what_the_selected_option_holds_and_leaving_it_frees_the_field(): void
    {
        $preset = self::preset();
        $chosen = $preset->evaluate([], [...self::PRIORITIES, DemoPreset::set('l', '/origin/lineage', 'brasa')]);
        $lineage = array_values(array_filter($preset->mechanics()->choiceAvailability($chosen->sheet, []), static fn (array $c): bool => $c['id'] === 'lineage'))[0];
        $this->assertSame([['path' => '/attributes/social/aparencia', 'value' => 0, 'label' => 'Marcas da brasa']], $lineage['fixes']);

        $other = $preset->evaluate($chosen->sheet, [DemoPreset::set('l2', '/origin/lineage', 'aurora'), DemoPreset::set('a', '/attributes/social/aparencia', 2)]);
        $this->assertTrue($other->result->isValid(), json_encode($other->result->toArray()));
        $this->assertSame(2, $other->sheet['attributes']['social']['aparencia']);
    }

    public function test_a_sheet_that_breaks_a_fix_is_reported_by_the_mechanics_check(): void
    {
        $preset = self::preset();
        $sheet = ['origin' => ['lineage' => 'brasa'], 'attributes' => ['social' => ['aparencia' => 2]]];
        $issues = $preset->mechanics()->check($sheet, $sheet, Phase::Creation, [], []);
        $this->assertContains('choice.fixed_value', array_map(static fn ($i): string => $i->ruleId, $issues));
    }

    /** @return array<string, mixed> a keep roll: a d20 per point keeping the best; at 0 two, keeping the worst */
    private static function keepRules(): array
    {
        $attr = ['var' => 'attr'];
        $preset = new DemoPreset('demo-linhagens');
        $rules = $preset->parts['rules'];
        $rules['rolls'] = [[
            'id' => 'teste', 'label' => 'Teste', 'strategy' => 'keep', 'faces' => 20,
            'inputs' => ['attr' => ['targets' => ['/attributes/physical/*']]],
            'count' => ['op' => 'if', 'args' => [['op' => 'compare', 'cmp' => 'ge', 'args' => [$attr, ['const' => 1]]], $attr, ['const' => 2]]],
            'keep_mode' => ['op' => 'if', 'args' => [['op' => 'compare', 'cmp' => 'ge', 'args' => [$attr, ['const' => 1]]], ['const' => 'highest'], ['const' => 'lowest']]],
            'modifier' => ['const' => 2],
            'target' => ['default' => 15, 'min' => 5, 'max' => 40],
            'critical_at_least' => 20,
        ]];

        return ['rules' => $rules, 'schema' => $preset->parts['sheet_schema']];
    }

    private static function roll(int $forca, array $faces): \Novtt\Engine\Resolution\RollOutcome
    {
        ['rules' => $rules, 'schema' => $schema] = self::keepRules();
        $preset = new DemoPreset('demo-linhagens', $rules);
        $preset->parts['sheet_schema']['properties']['attributes']['properties']['physical']['properties']['forca']['minimum'] = 0;
        $scope = $preset->mechanics()->scope(['attributes' => ['physical' => ['forca' => $forca]]], []);

        return (new PresetRolls($rules, new SchemaNavigator($preset->parts['sheet_schema'])))->roll('teste', $scope, new SequenceRandom($faces), ['inputs' => ['attr' => '/attributes/physical/forca']]);
    }

    public function test_keep_rolls_one_die_per_point_and_keeps_the_best(): void
    {
        $outcome = self::roll(3, [7, 18, 4]);
        $this->assertSame([7, 18, 4], $outcome->faces);
        $this->assertSame([false, true, false], $outcome->kept);
        $this->assertSame(20, $outcome->total, '18 + 2');
        $this->assertTrue($outcome->success);
    }

    public function test_keep_at_zero_rolls_two_and_keeps_the_worst(): void
    {
        $outcome = self::roll(0, [19, 6]);
        $this->assertSame([false, true], $outcome->kept);
        $this->assertSame(8, $outcome->total, '6 + 2');
        $this->assertFalse($outcome->success);
    }

    public function test_keep_reads_the_critical_on_the_kept_die(): void
    {
        $this->assertContains('critical', self::roll(2, [20, 3])->flags);
        $this->assertNotContains('critical', self::roll(0, [20, 3])->flags, 'at 0 the kept die is the 3');
    }

    public function test_keep_refuses_advantage_and_an_undeclared_parameter(): void
    {
        ['rules' => $rules, 'schema' => $schema] = self::keepRules();
        $preset = new DemoPreset('demo-linhagens', $rules);
        $rolls = new PresetRolls($rules, new SchemaNavigator($schema));
        $scope = $preset->mechanics()->scope(['attributes' => ['physical' => ['forca' => 2]]], []);
        $this->expectException(RollRejected::class);
        $rolls->prepare('teste', $scope, ['inputs' => ['attr' => '/attributes/physical/forca'], 'edge' => 'advantage']);
    }
}
