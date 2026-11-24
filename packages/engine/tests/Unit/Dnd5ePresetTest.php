<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\PresetCompatibility;
use Novtt\Engine\Presets\PublicationGate;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Resolution\RollRejected;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Tests\Support\SequenceRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fifth-edition presets built from the SRDs (CC BY 4.0): SRD 5.2.1 (2024 rules) and SRD 5.1 (2014
 * rules). Cases follow the tables of each SRD as recorded in the packages' SOURCE-REGISTER.md.
 */
final class Dnd5ePresetTest extends TestCase
{
    private const SRD_2024 = 'dnd-5e/srd-5.2.1/0.1.0';
    private const SRD_2014 = 'dnd-5e/srd-5.1/0.1.0';

    private const ATTRIBUTION_2024 = 'This work includes material from the System Reference Document 5.2.1 (“SRD 5.2.1”) by Wizards of the Coast LLC, available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0 International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.';
    private const ATTRIBUTION_2014 = 'This work includes material taken from the System Reference Document 5.1 (“SRD 5.1”) by Wizards of the Coast LLC and available at https://dnd.wizards.com/resources/systems-reference-document. The SRD 5.1 is licensed under the Creative Commons Attribution 4.0 International License available at https://creativecommons.org/licenses/by/4.0/legalcode.';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function packages(): iterable
    {
        yield 'SRD 5.2.1 (2024)' => [self::SRD_2024, self::ATTRIBUTION_2024];
        yield 'SRD 5.1 (2014)' => [self::SRD_2014, self::ATTRIBUTION_2014];
    }

    /** @return iterable<string, array{0: string}> */
    public static function editions(): iterable
    {
        yield 'SRD 5.2.1 (2024)' => [self::SRD_2024];
        yield 'SRD 5.1 (2014)' => [self::SRD_2014];
    }

    private static function preset(string $package): DemoPreset
    {
        return DemoPreset::package($package);
    }

    private static function rolls(DemoPreset $preset): PresetRolls
    {
        return new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
    }

    private static function method(string $package): string
    {
        return $package === self::SRD_2024 ? 'point_cost' : 'point_buy';
    }

    /** @param array<string, int> $base @return array<string, mixed> */
    private static function sheet(string $package, array $base, array $extra = []): array
    {
        return array_replace_recursive(['character' => ['ability_method' => self::method($package)], 'abilities' => ['base' => $base]], $extra);
    }

    #[DataProvider('packages')]
    public function test_the_package_is_engine_compatible_publishable_and_carries_the_exact_attribution(string $package, string $attribution): void
    {
        $p = self::preset($package)->parts;
        $this->assertSame([], (new PresetCompatibility())->check($p['manifest'], $p['sheet_schema'], $p['sheet_ui'], $p['rules'], $p['compendium'])->toArray()['issues']);
        $this->assertSame([], (new PublicationGate())->check($p['manifest'], $p['compendium'])->toArray()['issues'], 'rights approved, attribution present, hashes match');
        $this->assertTrue($p['manifest']['publication']['public']);
        $this->assertSame('CC-BY-4.0', $p['manifest']['license']['identifier']);
        $this->assertSame('approved', $p['manifest']['license']['rights_status']);
        $this->assertSame('licensed', $p['manifest']['provenance']['source_authenticity']);
        $this->assertStringStartsWith($attribution, $p['manifest']['license']['attribution'], 'the SRD\'s own statement, verbatim');
        $this->assertContains($attribution, $p['compendium']['attributions']);
        $this->assertStringNotContainsStringIgnoringCase('dungeons', $p['manifest']['display_name']);
    }

    #[DataProvider('editions')]
    public function test_any_subclass_is_representable_but_only_one_srd_subclass_per_class_is_filled(string $package): void
    {
        $compendium = self::preset($package)->parts['compendium'];
        $kinds = array_column($compendium['taxonomy'], null, 'kind');
        $this->assertSame('class', $kinds['subclass']['parent_kind']);
        $subclasses = array_values(array_filter($compendium['items'], static fn (array $i): bool => $i['kind'] === 'subclass'));
        $this->assertCount(12, $subclasses);
        $this->assertCount(12, array_unique(array_column($subclasses, 'parent_id')), 'one per class');
    }

    #[DataProvider('editions')]
    public function test_every_derived_value_evaluates_for_every_class_from_level_1_to_20(string $package): void
    {
        $preset = self::preset($package);
        [, , $empty] = $preset->mechanics()->materialize([], []);
        $this->assertSame([], $empty, 'a blank sheet');
        $classes = $preset->parts['sheet_schema']['properties']['character']['properties']['class']['enum'];
        $this->assertCount(12, $classes);
        foreach ($classes as $class) {
            foreach ([1, 3, 11, 20] as $level) {
                $sheet = self::sheet($package, ['str' => 15, 'dex' => 14, 'con' => 13], ['character' => ['class' => $class], 'progress' => ['level' => $level],
                    'inventory' => [['id' => 'rope', 'name' => 'Corda', 'quantity' => 2, 'weight' => 5]]]);
                [$out, , $issues] = $preset->mechanics()->materialize($sheet, []);
                $this->assertSame([], $issues, "{$class} {$level}");
                $this->assertGreaterThan(0, $out['combat']['hp_max']);
                $this->assertSame(10, $out['load']['carried']);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private static function levelOneOps(string $package): array
    {
        $ops = [DemoPreset::set('n', '/identity/name', 'Tora'), DemoPreset::set('c', '/character/class', 'fighter'), DemoPreset::set('m', '/character/ability_method', self::method($package))];
        if ($package === self::SRD_2024) {
            array_push($ops, DemoPreset::set('sp', '/origin/species', 'dwarf'), DemoPreset::set('b', '/origin/background', 'soldier'),
                DemoPreset::set('o1', '/abilities/origin/str', 2), DemoPreset::set('o2', '/abilities/origin/con', 1));
        } else {
            array_push($ops, DemoPreset::set('r', '/origin/race', 'dwarf'), DemoPreset::set('sr', '/origin/subrace', 'hill_dwarf'), DemoPreset::set('b', '/origin/background', 'acolyte'));
        }
        foreach (['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8] as $a => $v) {
            $ops[] = DemoPreset::set("a{$a}", "/abilities/base/{$a}", $v);
        }
        $ops[] = DemoPreset::set('k1', '/skills/perception', 1);
        $ops[] = DemoPreset::set('k2', '/skills/survival', 1);

        return $ops;
    }

    #[DataProvider('editions')]
    public function test_a_complete_level_1_character_passes_every_creation_step(string $package): void
    {
        $preset = self::preset($package);
        $outcome = $preset->evaluate([], self::levelOneOps($package));
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $progress = $preset->mechanics()->creationProgress($outcome->sheet, []);
        $this->assertTrue($progress['complete'], json_encode($progress['steps']) . json_encode(array_map(static fn ($i) => $i->toArray(), $progress['issues'])));

        $scope = $preset->mechanics()->scope($outcome->sheet, []);
        $this->assertSame($package === self::SRD_2024 ? 17 : 15, $scope->derived('score_str'), '2024: Soldier +2; 2014: dwarves do not raise STR');
        $this->assertSame(14 + ($package === self::SRD_2014 ? 1 : 0), $scope->derived('score_con'), '13 + Soldier 1 (2024) / + Dwarf 2 (2014)');
        $this->assertSame(13, $scope->derived('hp_max'), 'fighter 10 + CON +2 + Dwarven Toughness 1');

        $missing = $preset->evaluate([], array_values(array_filter(self::levelOneOps($package), static fn (array $op): bool => $op['id'] !== 'k2')));
        $this->assertFalse($preset->mechanics()->creationProgress($missing->sheet, [])['complete'], 'one class skill still to choose');
    }

    // ------------------------------------------------------------------ ability score generation

    #[DataProvider('editions')]
    public function test_point_buy_spends_27_points_and_15_costs_9(string $package): void
    {
        $preset = self::preset($package);
        $one = $preset->evaluate(self::sheet($package, []), [DemoPreset::set('s', '/abilities/base/str', 15)]);
        $this->assertTrue($one->result->isValid(), json_encode($one->result->toArray()));
        $this->assertSame(18, $one->balances['point_buy'], '15 costs 9 of 27');

        $ops = [];
        foreach (['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8] as $a => $v) {
            $ops[] = DemoPreset::set($a, "/abilities/base/{$a}", $v);
        }
        $all = $preset->evaluate(self::sheet($package, []), $ops);
        $this->assertTrue($all->result->isValid(), json_encode($all->result->toArray()));
        $this->assertSame(0, $all->balances['point_buy'], '9 + 7 + 5 + 4 + 2 + 0 = 27');
        $scope = $preset->mechanics()->scope($all->sheet, []);
        $this->assertSame(2, $scope->derived('mod_str'));
        $this->assertSame(-1, $scope->derived('mod_cha'));
    }

    #[DataProvider('editions')]
    public function test_point_buy_rejects_scores_above_15_and_overspending(string $package): void
    {
        $preset = self::preset($package);
        $this->assertContains('limit.phase_max', DemoPreset::ruleIds($preset->evaluate(self::sheet($package, []), [DemoPreset::set('s', '/abilities/base/str', 16)])));

        $ops = [];
        foreach (['str', 'dex', 'con', 'int'] as $a) {
            $ops[] = DemoPreset::set($a, "/abilities/base/{$a}", 15);
        }
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($preset->evaluate(self::sheet($package, []), $ops)), '36 points > 27');
    }

    // ------------------------------------------------------------------ class

    #[DataProvider('editions')]
    public function test_a_class_holds_its_saving_throw_proficiencies(string $package): void
    {
        $preset = self::preset($package);
        $wizard = $preset->evaluate([], [DemoPreset::set('c', '/character/class', 'wizard')]);
        $this->assertTrue($wizard->result->isValid(), json_encode($wizard->result->toArray()));
        $this->assertTrue($wizard->sheet['saving_throws']['int']);
        $this->assertTrue($wizard->sheet['saving_throws']['wis']);

        $fighter = $preset->evaluate($wizard->sheet, [DemoPreset::set('c', '/character/class', 'fighter')]);
        $this->assertTrue($fighter->result->isValid(), json_encode($fighter->result->toArray()));
        $this->assertTrue($fighter->sheet['saving_throws']['str']);
        $this->assertTrue($fighter->sheet['saving_throws']['con']);
        $this->assertFalse($fighter->sheet['saving_throws']['int'], 'leaving the class releases its holds');
        $this->assertTrue($fighter->sheet['proficiencies']['armor_heavy']);

        $forged = $preset->evaluate($fighter->sheet, [DemoPreset::set('f', '/saving_throws/str', false)], Phase::Creation, [], Actor::Master);
        $this->assertContains('choice.fixed_value', DemoPreset::ruleIds($forged));

        $scope = $preset->mechanics()->scope(self::sheet($package, ['con' => 14], ['character' => ['class' => 'fighter'], 'saving_throws' => ['con' => true]]), []);
        $this->assertSame(4, $scope->derived('save_con'), 'CON +2 and proficiency +2');
    }

    /** @return iterable<string, array{0: string, 1: int, 2: int}> */
    public static function proficiencyCases(): iterable
    {
        foreach ([[1, 2], [4, 2], [5, 3], [9, 4], [12, 4], [13, 5], [17, 6], [20, 6]] as [$level, $bonus]) {
            yield "2024 level {$level}" => [self::SRD_2024, $level, $bonus];
            yield "2014 level {$level}" => [self::SRD_2014, $level, $bonus];
        }
    }

    #[DataProvider('proficiencyCases')]
    public function test_proficiency_bonus_follows_the_level(string $package, int $level, int $bonus): void
    {
        $this->assertSame($bonus, self::preset($package)->mechanics()->scope(['progress' => ['level' => $level]], [])->derived('pb'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: int, 4: int}> */
    public static function hitPointCases(): iterable
    {
        foreach ([self::SRD_2024, self::SRD_2014] as $package) {
            $e = $package === self::SRD_2024 ? '2024' : '2014';
            yield "{$e} barbarian 1, CON 14" => [$package, 'barbarian', 1, 14, 14];
            yield "{$e} fighter 1, CON 14" => [$package, 'fighter', 1, 14, 12];
            yield "{$e} wizard 1, CON 8" => [$package, 'wizard', 1, 8, 5];
            yield "{$e} fighter 5, CON 14" => [$package, 'fighter', 5, 14, 44];
        }
    }

    #[DataProvider('hitPointCases')]
    public function test_hit_points_are_the_die_maximum_plus_con_then_the_fixed_value_per_level(string $package, string $class, int $level, int $con, int $hp): void
    {
        $sheet = self::sheet($package, ['con' => $con], ['character' => ['class' => $class], 'progress' => ['level' => $level]]);
        $this->assertSame($hp, self::preset($package)->mechanics()->scope($sheet, [])->derived('hp_max'));
    }

    #[DataProvider('editions')]
    public function test_a_level_5_full_caster_has_4_3_2_slots(string $package): void
    {
        $scope = self::preset($package)->mechanics()->scope(self::sheet($package, ['int' => 15], ['character' => ['class' => 'wizard'], 'progress' => ['level' => 5]]), []);
        $slots = array_map(static fn (int $k): mixed => $scope->derived("slot_{$k}"), range(1, 9));
        $this->assertSame([4, 3, 2, 0, 0, 0, 0, 0, 0], $slots);
        $this->assertSame(13, $scope->derived('spell_save_dc'), '8 + PB 3 + INT 15 (+2)');
        $this->assertSame(5, $scope->derived('spell_attack'));
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>, 2: int}> */
    public static function armorClassCases(): iterable
    {
        foreach ([self::SRD_2024, self::SRD_2014] as $package) {
            $e = $package === self::SRD_2024 ? '2024' : '2014';
            yield "{$e} no armor: 10 + DEX" => [$package, ['character' => ['class' => 'fighter']], 12];
            yield "{$e} chain mail + shield" => [$package, ['character' => ['class' => 'fighter'], 'combat' => ['armor' => 'chain_mail', 'shield' => true]], 18];
            yield "{$e} half plate caps DEX at 2" => [$package, ['abilities' => ['base' => ['dex' => 18]], 'combat' => ['armor' => 'half_plate']], 17];
            yield "{$e} barbarian unarmored: 10 + DEX + CON, shield allowed" => [$package, ['character' => ['class' => 'barbarian'], 'combat' => ['shield' => true]], 16];
            yield "{$e} monk unarmored: 10 + DEX + WIS" => [$package, ['character' => ['class' => 'monk']], 13];
            yield "{$e} monk with a shield loses Unarmored Defense" => [$package, ['character' => ['class' => 'monk'], 'combat' => ['shield' => true]], 14];
        }
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('armorClassCases')]
    public function test_armor_class_follows_the_srd_armor_table_and_unarmored_defense(string $package, array $extra, int $ac): void
    {
        $sheet = self::sheet($package, ['dex' => 14, 'con' => 14, 'wis' => 12], $extra);
        $this->assertSame($ac, self::preset($package)->mechanics()->scope($sheet, [])->derived('armor_class'));
    }

    // ------------------------------------------------------------------ rolls

    #[DataProvider('editions')]
    public function test_a_skill_check_with_advantage_keeps_the_highest_d20(string $package): void
    {
        $preset = self::preset($package);
        $scope = $preset->mechanics()->scope(self::sheet($package, ['dex' => 14], ['skills' => ['stealth' => 1]]), []);
        $this->assertSame(4, $scope->derived('skill_stealth'), 'DEX +2, proficiency +2');
        $outcome = self::rolls($preset)->roll('skill_check', $scope, new SequenceRandom([5, 17]), ['inputs' => ['skill' => '/skill_bonus/stealth'], 'edge' => 'advantage']);
        $this->assertSame([5, 17], $outcome->faces);
        $this->assertSame([false, true], $outcome->kept);
        $this->assertSame(21, $outcome->total);

        $worse = self::rolls($preset)->roll('skill_check', $scope, new SequenceRandom([5, 17]), ['inputs' => ['skill' => '/skill_bonus/stealth'], 'edge' => 'disadvantage']);
        $this->assertSame(9, $worse->total);
    }

    #[DataProvider('editions')]
    public function test_an_attack_flags_a_natural_20_and_a_natural_1_and_clients_cannot_pick_dice(string $package): void
    {
        $preset = self::preset($package);
        $scope = $preset->mechanics()->scope(self::sheet($package, ['str' => 15]), []);
        $hit = self::rolls($preset)->roll('attack', $scope, new SequenceRandom([20]), ['inputs' => ['bonus' => '/combat/attack/melee'], 'target' => 30]);
        $this->assertContains('critical', $hit->flags);
        $this->assertSame(24, $hit->total, '20 + STR 2 + PB 2');
        $miss = self::rolls($preset)->roll('attack', $scope, new SequenceRandom([1]), ['inputs' => ['bonus' => '/combat/attack/melee']]);
        $this->assertContains('fumble', $miss->flags);

        $this->expectException(RollRejected::class);
        self::rolls($preset)->roll('skill_check', $scope, new SequenceRandom([10]), ['inputs' => ['skill' => '/abilities/base/str']]);
    }

    // ------------------------------------------------------------------ progression

    #[DataProvider('editions')]
    public function test_a_level_needs_the_srd_xp_or_a_master_milestone(string $package): void
    {
        $preset = self::preset($package);
        $up = [DemoPreset::set('l', '/progress/level', 2)];
        $this->assertContains('progression.level_not_earned', DemoPreset::ruleIds($preset->evaluate([], $up, Phase::Advancement, ['xp' => 299])));
        $this->assertNotContains('progression.level_not_earned', DemoPreset::ruleIds($preset->evaluate([], $up, Phase::Advancement, ['xp' => 300])));
        $this->assertNotContains('progression.level_not_earned', DemoPreset::ruleIds($preset->evaluate([], $up, Phase::Advancement, ['milestones' => 1])));
    }

    #[DataProvider('editions')]
    public function test_the_subclass_needs_its_class_and_level(string $package): void
    {
        $preset = self::preset($package);
        $early = $preset->evaluate(['character' => ['class' => 'fighter']], [DemoPreset::set('s', '/character/subclass', 'champion')]);
        $this->assertContains('choice.prerequisite_unmet', DemoPreset::ruleIds($early));
        $wrong = $preset->evaluate(['character' => ['class' => 'rogue'], 'progress' => ['level' => 3]], [DemoPreset::set('s', '/character/subclass', 'champion')]);
        $this->assertContains('choice.prerequisite_unmet', DemoPreset::ruleIds($wrong));
        $ok = $preset->evaluate(['character' => ['class' => 'fighter'], 'progress' => ['level' => 3]], [DemoPreset::set('s', '/character/subclass', 'champion')]);
        $this->assertTrue($ok->result->isValid(), json_encode($ok->result->toArray()));
    }

    #[DataProvider('editions')]
    public function test_class_skills_come_from_the_class_list_and_background_skills_are_free(string $package): void
    {
        $preset = self::preset($package);
        $start = $preset->evaluate([], [DemoPreset::set('c', '/character/class', 'fighter'), DemoPreset::set('b', '/origin/background', 'acolyte')]);
        $this->assertTrue($start->result->isValid(), json_encode($start->result->toArray()));
        $this->assertSame(1, $start->sheet['skills']['insight'], 'held by the background');
        $this->assertSame(1, $start->sheet['skills']['religion']);
        $this->assertSame(2, $start->balances['skill_picks'], 'fighter chooses 2, background skills are given');

        $picked = $preset->evaluate($start->sheet, [DemoPreset::set('a', '/skills/athletics', 1), DemoPreset::set('p', '/skills/perception', 1)]);
        $this->assertTrue($picked->result->isValid(), json_encode($picked->result->toArray()));
        $this->assertSame(0, $picked->balances['skill_picks']);

        $offList = $preset->evaluate($start->sheet, [DemoPreset::set('a', '/skills/arcana', 1)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($offList), 'Arcana is not a fighter skill');
    }

    #[DataProvider('editions')]
    public function test_expertise_needs_proficiency_and_the_class_budget(string $package): void
    {
        $preset = self::preset($package);
        $rogue = $preset->evaluate([], [DemoPreset::set('c', '/character/class', 'rogue'), DemoPreset::set('s', '/skills/stealth', 1), DemoPreset::set('e', '/expertise/stealth', 1)]);
        $this->assertTrue($rogue->result->isValid(), json_encode($rogue->result->toArray()));
        $this->assertSame(1, $rogue->balances['expertise']);

        $noProf = $preset->evaluate($rogue->sheet, [DemoPreset::set('e', '/expertise/arcana', 1)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($noProf));

        $fighter = $preset->evaluate([], [DemoPreset::set('c', '/character/class', 'fighter'), DemoPreset::set('s', '/skills/athletics', 1), DemoPreset::set('e', '/expertise/athletics', 1)]);
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($fighter));
    }

    // ------------------------------------------------------------------ 2024 specifics (SRD 5.2.1)

    public function test_2024_background_raises_only_its_three_abilities(): void
    {
        $preset = self::preset(self::SRD_2024);
        $soldier = $preset->evaluate([], [DemoPreset::set('b', '/origin/background', 'soldier'), DemoPreset::set('s', '/abilities/origin/str', 2), DemoPreset::set('c', '/abilities/origin/con', 1)]);
        $this->assertTrue($soldier->result->isValid(), json_encode($soldier->result->toArray()));
        $this->assertSame(0, $soldier->balances['origin_asi']);

        $wrong = $preset->evaluate(['origin' => ['background' => 'soldier']], [DemoPreset::set('i', '/abilities/origin/int', 1)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($wrong), 'Intelligence is not a Soldier ability');
        $three = $preset->evaluate(['origin' => ['background' => 'soldier']], [DemoPreset::set('s', '/abilities/origin/str', 3)]);
        $this->assertContains('limit.phase_max', DemoPreset::ruleIds($three));
    }

    public function test_2024_paladins_cast_from_level_1_and_alert_adds_proficiency_to_initiative(): void
    {
        $preset = self::preset(self::SRD_2024);
        $this->assertSame(2, $preset->mechanics()->scope(['character' => ['class' => 'paladin']], [])->derived('slot_1'));
        $criminal = $preset->mechanics()->scope(self::sheet(self::SRD_2024, ['dex' => 14], ['origin' => ['background' => 'criminal']]), []);
        $this->assertSame(4, $criminal->derived('initiative'), 'DEX +2 and Alert +PB 2');
        $this->assertSame('Alerta', $criminal->derived('background_feat'));
    }

    public function test_2024_exhaustion_lowers_every_d20_test_by_2_per_level(): void
    {
        $preset = self::preset(self::SRD_2024);
        $scope = $preset->mechanics()->scope(self::sheet(self::SRD_2024, ['dex' => 14], ['conditions' => ['exhaustion' => 2]]), []);
        $outcome = self::rolls($preset)->roll('ability_check', $scope, new SequenceRandom([10]), ['inputs' => ['ability' => '/checks/dex']]);
        $this->assertSame(8, $outcome->total, '10 + DEX 2 − 4');
    }

    public function test_2024_standard_array_must_use_each_value_once(): void
    {
        $preset = self::preset(self::SRD_2024);
        $array = ['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8];
        $ops = [];
        foreach ($array as $a => $v) {
            $ops[] = DemoPreset::set($a, "/abilities/base/{$a}", $v);
        }
        $this->assertTrue($preset->evaluate(['character' => ['ability_method' => 'standard_array']], $ops)->result->isValid());

        $ops = [];
        foreach (['str' => 15, 'dex' => 15, 'con' => 12, 'int' => 11, 'wis' => 10, 'cha' => 8] as $a => $v) { // 9 + 9 + 4 + 3 + 2 + 0 = 27
            $ops[] = DemoPreset::set($a, "/abilities/base/{$a}", $v);
        }
        $this->assertTrue($preset->evaluate(['character' => ['ability_method' => 'point_cost']], $ops)->result->isValid(), 'a valid point cost spread');
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($preset->evaluate(['character' => ['ability_method' => 'standard_array']], $ops)));
    }

    // ------------------------------------------------------------------ 2014 specifics (SRD 5.1)

    public function test_2014_race_and_subrace_hold_their_ability_increases(): void
    {
        $preset = self::preset(self::SRD_2014);
        $dwarf = $preset->evaluate([], [DemoPreset::set('r', '/origin/race', 'dwarf'), DemoPreset::set('s', '/origin/subrace', 'hill_dwarf')]);
        $this->assertTrue($dwarf->result->isValid(), json_encode($dwarf->result->toArray()));
        $this->assertSame(2, $dwarf->sheet['abilities']['origin']['con']);
        $this->assertSame(1, $dwarf->sheet['abilities']['origin']['wis']);
        $this->assertContains('choice.fixed_value', DemoPreset::ruleIds($preset->evaluate($dwarf->sheet, [DemoPreset::set('c', '/abilities/origin/con', 0)])));

        $scope = $preset->mechanics()->scope(self::sheet(self::SRD_2014, ['con' => 14], ['character' => ['class' => 'fighter'], 'origin' => ['race' => 'dwarf', 'subrace' => 'hill_dwarf'], 'abilities' => ['origin' => ['con' => 2, 'wis' => 1]]]), []);
        $this->assertSame(16, $scope->derived('score_con'));
        $this->assertSame(14, $scope->derived('hp_max'), 'fighter 10 + CON 3 + Dwarven Toughness 1');
        $this->assertSame(25, $scope->derived('speed'));

        $human = $preset->evaluate([], [DemoPreset::set('r', '/origin/race', 'human')]);
        $this->assertSame(['str' => 1, 'dex' => 1, 'con' => 1, 'int' => 1, 'wis' => 1, 'cha' => 1], $human->sheet['abilities']['origin']);

        $wrongSub = $preset->evaluate(['origin' => ['race' => 'elf']], [DemoPreset::set('s', '/origin/subrace', 'hill_dwarf')]);
        $this->assertContains('choice.prerequisite_unmet', DemoPreset::ruleIds($wrongSub));
    }

    public function test_2014_half_elf_adds_charisma_2_and_two_other_abilities_by_1(): void
    {
        $preset = self::preset(self::SRD_2014);
        $halfElf = $preset->evaluate([], [DemoPreset::set('r', '/origin/race', 'half_elf'), DemoPreset::set('s', '/abilities/origin/str', 1), DemoPreset::set('d', '/abilities/origin/dex', 1)]);
        $this->assertTrue($halfElf->result->isValid(), json_encode($halfElf->result->toArray()));
        $this->assertSame(2, $halfElf->sheet['abilities']['origin']['cha']);
        $this->assertSame(2, $halfElf->balances['skill_picks'], 'no class yet: only the two Skill Versatility picks');

        $third = $preset->evaluate($halfElf->sheet, [DemoPreset::set('c', '/abilities/origin/con', 1)]);
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($third));
        $double = $preset->evaluate(['origin' => ['race' => 'half_elf']], [DemoPreset::set('s', '/abilities/origin/str', 2)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($double));

        $dwarf = $preset->evaluate([], [DemoPreset::set('r', '/origin/race', 'dwarf'), DemoPreset::set('s', '/abilities/origin/str', 1)]);
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($dwarf), 'only the half-elf chooses');
    }

    public function test_2014_half_casters_start_at_level_2_and_jack_of_all_trades_reaches_initiative(): void
    {
        $preset = self::preset(self::SRD_2014);
        $this->assertSame(0, $preset->mechanics()->scope(['character' => ['class' => 'paladin']], [])->derived('slot_1'));
        $this->assertSame(2, $preset->mechanics()->scope(['character' => ['class' => 'paladin'], 'progress' => ['level' => 2]], [])->derived('slot_1'));
        $bard = $preset->mechanics()->scope(self::sheet(self::SRD_2014, ['dex' => 14], ['character' => ['class' => 'bard'], 'progress' => ['level' => 2]]), []);
        $this->assertSame(3, $bard->derived('initiative'), 'DEX +2 and half of PB 2');
        $this->assertSame(3, $bard->derived('skill_stealth'));
    }
}
