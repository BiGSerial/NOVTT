<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\PresetCompatibility;
use Novtt\Engine\Presets\PublicationGate;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Tests\Support\SequenceRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vampire: Dark Ages 20th Anniversary preset (internal use, distribution blocked). Every value in
 * the package is "a conferir" (SOURCE-REGISTER.md): these tests exercise the rules as wired, they
 * do not certify them as official.
 */
final class VampireDarkAgesPresetTest extends TestCase
{
    private const PACKAGE = 'world-of-darkness/vampire-dark-ages-v20/0.1.0';

    private static function preset(): DemoPreset
    {
        return DemoPreset::package(self::PACKAGE);
    }

    public function test_the_package_is_engine_compatible_but_blocked_for_distribution(): void
    {
        $p = self::preset()->parts;
        $this->assertSame([], (new PresetCompatibility())->check($p['manifest'], $p['sheet_schema'], $p['sheet_ui'], $p['rules'], $p['compendium'])->toArray()['issues']);

        $gate = DemoPreset::ruleIds((new PublicationGate())->check($p['manifest'], $p['compendium']));
        $this->assertContains('rights.not_approved', $gate);
        $this->assertFalse($p['manifest']['publication']['public']);
        $this->assertSame('rights_review', $p['manifest']['license']['rights_status']);
        $this->assertSame('official', $p['manifest']['provenance']['source_authenticity']);
        foreach ($p['compendium']['items'] as $item) {
            $this->assertSame('', $item['summary']);
            $this->assertSame('unverified', $item['source']['review_status']);
        }
    }

    public function test_dark_ages_lists_its_own_abilities_and_has_no_sect(): void
    {
        $schema = self::preset()->parts['sheet_schema']['properties'];
        $this->assertArrayHasKey('hearth_wisdom', $schema['abilities']['properties']['knowledges']['properties']);
        $this->assertArrayHasKey('ride', $schema['abilities']['properties']['skills']['properties']);
        $this->assertArrayNotHasKey('computer', $schema['abilities']['properties']['knowledges']['properties']);
        $this->assertArrayNotHasKey('sect', $schema['clan']['properties']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int}> */
    public static function priorities(): iterable
    {
        yield 'physical primary' => ['physical', 'primary', 7];
        yield 'social secondary' => ['social', 'secondary', 5];
        yield 'mental tertiary' => ['mental', 'tertiary', 3];
        yield 'talents primary' => ['talents', 'primary', 13];
        yield 'skills secondary' => ['skills', 'secondary', 9];
        yield 'knowledges tertiary' => ['knowledges', 'tertiary', 5];
    }

    #[DataProvider('priorities')]
    public function test_priorities_give_7_5_3_attribute_and_13_9_5_ability_points(string $group, string $priority, int $points): void
    {
        $outcome = self::preset()->evaluate([], [DemoPreset::set('p', "/priorities/{$group}", $priority)]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame($points, $outcome->balances[$group]);
    }

    public function test_an_ability_takes_at_most_3_priority_dots(): void
    {
        $outcome = self::preset()->evaluate([], [DemoPreset::set('p', '/priorities/skills', 'tertiary'), DemoPreset::set('r', '/abilities/skills/ride', 5)]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(2, $outcome->balances['skills'], 'three dots from the priority');
        $this->assertSame(11, $outcome->balances['freebies'], 'two dots above 3 at 2 freebies each');
    }

    public function test_nosferatu_holds_appearance_at_0_and_refunds_the_points_spent_there(): void
    {
        $preset = self::preset();
        $before = $preset->evaluate([], [DemoPreset::set('p', '/priorities/social', 'secondary'), DemoPreset::set('a', '/attributes/social/appearance', 3)]);
        $this->assertSame(3, $before->balances['social']);

        $after = $preset->evaluate($before->sheet, [DemoPreset::set('c', '/clan/clan', 'nosferatu')]);
        $this->assertTrue($after->result->isValid(), json_encode($after->result->toArray()));
        $this->assertSame(0, $after->sheet['attributes']['social']['appearance']);
        $this->assertSame(5, $after->balances['social']);

        $back = $preset->evaluate($after->sheet, [DemoPreset::set('c', '/clan/clan', 'toreador')]);
        $this->assertSame(1, $back->sheet['attributes']['social']['appearance'], 'leaving the clan returns Appearance to its default');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function disciplineCosts(): iterable
    {
        yield 'Cappadocian Mortis (clan): current × 5' => ['cappadocian', 'mortis', 2, 10];
        yield 'Ventrue Mortis (other): current × 7' => ['ventrue', 'mortis', 2, 14];
        yield 'new Discipline: 10' => ['salubri', 'potence', 0, 10];
    }

    #[DataProvider('disciplineCosts')]
    public function test_disciplines_cost_xp_by_clan(string $clan, string $discipline, int $current, int $cost): void
    {
        $sheet = ['clan' => ['clan' => $clan], 'disciplines' => [$discipline => $current]];
        $outcome = self::preset()->evaluate($sheet, [DemoPreset::set('d', "/disciplines/{$discipline}", $current + 1)], Phase::Advancement, ['xp' => 30]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(30 - $cost, $outcome->balances['xp']);
    }

    public function test_creation_disciplines_come_from_the_clan(): void
    {
        $preset = self::preset();
        $clan = $preset->evaluate([], [DemoPreset::set('c', '/clan/clan', 'cappadocian'), DemoPreset::set('m', '/disciplines/mortis', 3)]);
        $this->assertSame(0, $clan->balances['disciplines']);
        $other = $preset->evaluate([], [DemoPreset::set('c', '/clan/clan', 'cappadocian'), DemoPreset::set('m', '/disciplines/obeah', 1)]);
        $this->assertSame(3, $other->balances['disciplines']);
        $this->assertSame(8, $other->balances['freebies']);
    }

    /** @return iterable<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> */
    public static function generations(): iterable
    {
        yield 'no Generation background: 12th' => [0, 12, 11, 1, 5];
        yield 'Generation 3: 9th' => [3, 9, 14, 2, 5];
        yield 'Generation 5: 7th' => [5, 7, 20, 4, 6];
    }

    #[DataProvider('generations')]
    public function test_the_generation_background_sets_generation_blood_pool_and_trait_max(int $background, int $generation, int $pool, int $perTurn, int $traitMax): void
    {
        $scope = self::preset()->mechanics()->scope(['backgrounds' => ['generation' => $background]], []);
        $this->assertSame($generation, $scope->derived('generation'));
        $this->assertSame($pool, $scope->derived('blood_pool_max'));
        $this->assertSame($perTurn, $scope->derived('blood_per_turn'));
        $this->assertSame($traitMax, $scope->derived('trait_max'));
    }

    public function test_a_sixth_dot_needs_the_seventh_generation(): void
    {
        $sheet = ['attributes' => ['physical' => ['strength' => 5]]];
        $twelfth = self::preset()->evaluate($sheet, [DemoPreset::set('s', '/attributes/physical/strength', 6)], Phase::Advancement, ['xp' => 30]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($twelfth));

        $seventh = self::preset()->evaluate($sheet + ['backgrounds' => ['generation' => 5]], [DemoPreset::set('s', '/attributes/physical/strength', 6)], Phase::Advancement, ['xp' => 30]);
        $this->assertTrue($seventh->result->isValid(), json_encode($seventh->result->toArray()));
        $this->assertSame(10, $seventh->balances['xp'], 'current 5 × 4');
    }

    public function test_the_road_of_humanity_uses_conscience_and_self_control_other_roads_let_the_player_choose(): void
    {
        $preset = self::preset();
        $humanity = $preset->evaluate([], [DemoPreset::set('r', '/morality/path', 'humanity')]);
        $this->assertSame('conscience', $humanity->sheet['morality']['first_virtue']);
        $this->assertContains('choice.fixed_value', DemoPreset::ruleIds($preset->evaluate($humanity->sheet, [DemoPreset::set('v', '/morality/first_virtue', 'conviction')])));

        $beast = $preset->evaluate([], [DemoPreset::set('r', '/morality/path', 'beast'), DemoPreset::set('v', '/morality/first_virtue', 'conviction'), DemoPreset::set('i', '/morality/second_virtue', 'instinct')]);
        $this->assertTrue($beast->result->isValid(), json_encode($beast->result->toArray()));
    }

    public function test_road_rating_costs_current_x2_in_advancement(): void
    {
        $outcome = self::preset()->evaluate(['morality' => ['rating' => 4]], [DemoPreset::set('r', '/morality/rating', 5)], Phase::Advancement, ['xp' => 10]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(2, $outcome->balances['xp']);
    }

    public function test_a_d10_pool_cancels_successes_with_1s_and_botches_without_successes(): void
    {
        $preset = self::preset();
        $rolls = new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
        $scope = $preset->mechanics()->scope(['attributes' => ['physical' => ['dexterity' => 2]], 'abilities' => ['skills' => ['archery' => 1]]], []);
        $inputs = ['inputs' => ['attribute' => '/attributes/physical/dexterity', 'ability' => '/abilities/skills/archery'], 'difficulty' => 7];

        $hit = $rolls->roll('test', $scope, new SequenceRandom([7, 9, 1]), $inputs);
        $this->assertSame(1, $hit->total);
        $this->assertSame([], $hit->flags);

        $botch = $rolls->roll('test', $scope, new SequenceRandom([1, 6, 2]), $inputs);
        $this->assertSame(0, $botch->total);
        $this->assertSame(['botch'], $botch->flags, 'a 6 is no success at difficulty 7');
    }
}
