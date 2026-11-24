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
 * Vampire: The Masquerade 20th Anniversary preset (internal use, distribution blocked). Every value
 * in the package is "a conferir" (SOURCE-REGISTER.md): these tests exercise the rules as wired, they
 * do not certify them as official.
 */
final class VampireV20PresetTest extends TestCase
{
    private const PACKAGE = 'world-of-darkness/vampire-v20/0.1.0';

    private static function preset(): DemoPreset
    {
        return DemoPreset::package(self::PACKAGE);
    }

    /** @param array<string, mixed> $sheet @return array<string, mixed> */
    private static function step(array $sheet, string $id): array
    {
        $progress = self::preset()->mechanics()->creationProgress($sheet, []);

        return array_values(array_filter($progress['steps'], static fn (array $s): bool => $s['id'] === $id))[0];
    }

    public function test_the_package_is_engine_compatible_but_blocked_for_distribution(): void
    {
        $p = self::preset()->parts;
        $this->assertSame([], (new PresetCompatibility())->check($p['manifest'], $p['sheet_schema'], $p['sheet_ui'], $p['rules'], $p['compendium'])->toArray()['issues']);

        $gate = DemoPreset::ruleIds((new PublicationGate())->check($p['manifest'], $p['compendium']));
        $this->assertContains('rights.not_approved', $gate, 'Dark Pack does not cover paid apps: not publishable');
        $this->assertContains('rights.unverified_source', $gate);
        $this->assertFalse($p['manifest']['publication']['public']);
        $this->assertSame('rights_review', $p['manifest']['license']['rights_status']);
        $this->assertSame('official', $p['manifest']['provenance']['source_authenticity']);
        $this->assertTrue($p['rules']['governance']['lock_during_session']);
    }

    public function test_the_compendium_carries_names_and_refs_only(): void
    {
        foreach (self::preset()->parts['compendium']['items'] as $item) {
            $this->assertSame('', $item['summary'], "{$item['canonical_id']}: no rulebook prose");
            $this->assertSame('unverified', $item['source']['review_status']);
            $this->assertSame('rights_review', $item['source']['rights_status']);
        }
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
        $this->assertSame(15, $outcome->balances['freebies']);
    }

    public function test_the_same_priority_twice_is_refused(): void
    {
        $outcome = self::preset()->evaluate([], [DemoPreset::set('a', '/priorities/physical', 'primary'), DemoPreset::set('b', '/priorities/social', 'primary')]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($outcome));
    }

    public function test_attributes_start_at_1_and_dots_beyond_the_priority_cost_5_freebies(): void
    {
        $outcome = self::preset()->evaluate([], [
            DemoPreset::set('p', '/priorities/physical', 'primary'),
            DemoPreset::set('s', '/attributes/physical/strength', 5),
            DemoPreset::set('d', '/attributes/physical/dexterity', 4),
            DemoPreset::set('t', '/attributes/physical/stamina', 2),
        ]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(0, $outcome->balances['physical'], '4 + 3 dots above the free first dot');
        $this->assertSame(10, $outcome->balances['freebies'], 'the 8th dot is a freebie at 5');
    }

    public function test_an_ability_takes_at_most_3_priority_dots_and_a_4th_needs_freebies(): void
    {
        $preset = self::preset();
        $three = $preset->evaluate([], [DemoPreset::set('p', '/priorities/talents', 'primary'), DemoPreset::set('a', '/abilities/talents/alertness', 4)]);
        $this->assertTrue($three->result->isValid(), json_encode($three->result->toArray()));
        $this->assertSame(10, $three->balances['talents'], 'only three dots from the priority');
        $this->assertSame(13, $three->balances['freebies'], 'the 4th dot costs 2 freebies');

        // Spend every freebie first: then the 4th dot cannot be bought at creation.
        $broke = $preset->evaluate([], [
            DemoPreset::set('b1', '/backgrounds/allies', 5), DemoPreset::set('b2', '/backgrounds/contacts', 5), DemoPreset::set('b3', '/backgrounds/resources', 5), DemoPreset::set('b4', '/backgrounds/herd', 5),
            DemoPreset::set('p', '/priorities/talents', 'primary'), DemoPreset::set('a', '/abilities/talents/alertness', 4),
        ]);
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($broke));
    }

    public function test_nosferatu_holds_appearance_at_0_and_refunds_the_points_spent_there(): void
    {
        $preset = self::preset();
        $before = $preset->evaluate([], [DemoPreset::set('p', '/priorities/social', 'primary'), DemoPreset::set('a', '/attributes/social/appearance', 4)]);
        $this->assertTrue($before->result->isValid(), json_encode($before->result->toArray()));
        $this->assertSame(4, $before->balances['social']);

        $after = $preset->evaluate($before->sheet, [DemoPreset::set('c', '/clan/clan', 'nosferatu')]);
        $this->assertTrue($after->result->isValid(), json_encode($after->result->toArray()));
        $this->assertSame(0, $after->sheet['attributes']['social']['appearance']);
        $this->assertSame(7, $after->balances['social'], 'the three dots come back');

        $raise = $preset->evaluate($after->sheet, [DemoPreset::set('a', '/attributes/social/appearance', 1)]);
        $this->assertContains('choice.fixed_value', DemoPreset::ruleIds($raise));

        $availability = $preset->mechanics()->choiceAvailability($after->sheet, []);
        $clan = array_values(array_filter($availability, static fn (array $c): bool => $c['id'] === 'clan'))[0];
        $this->assertSame('/attributes/social/appearance', $clan['fixes'][0]['path']);
    }

    public function test_other_clans_cannot_drop_appearance_to_0(): void
    {
        $outcome = self::preset()->evaluate([], [DemoPreset::set('c', '/clan/clan', 'toreador'), DemoPreset::set('a', '/attributes/social/appearance', 0)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($outcome));
    }

    public function test_creation_disciplines_come_from_the_clan_and_others_cost_freebies(): void
    {
        $preset = self::preset();
        $clan = $preset->evaluate([], [DemoPreset::set('c', '/clan/clan', 'brujah'), DemoPreset::set('p', '/disciplines/potence', 2), DemoPreset::set('r', '/disciplines/celerity', 1)]);
        $this->assertTrue($clan->result->isValid(), json_encode($clan->result->toArray()));
        $this->assertSame(0, $clan->balances['disciplines']);

        $other = $preset->evaluate([], [DemoPreset::set('c', '/clan/clan', 'brujah'), DemoPreset::set('a', '/disciplines/auspex', 1)]);
        $this->assertTrue($other->result->isValid(), json_encode($other->result->toArray()));
        $this->assertSame(3, $other->balances['disciplines'], 'Auspex is not a Brujah Discipline');
        $this->assertSame(8, $other->balances['freebies'], 'a Discipline dot costs 7 freebies');

        $caitiff = $preset->evaluate([], [DemoPreset::set('c', '/clan/clan', 'caitiff'), DemoPreset::set('a', '/disciplines/auspex', 3)]);
        $this->assertSame(0, $caitiff->balances['disciplines'], 'the clanless pick any Discipline');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function disciplineCosts(): iterable
    {
        yield 'clan Discipline: current × 5' => ['brujah', 'potence', 2, 10];
        yield 'out-of-clan Discipline: current × 7' => ['brujah', 'auspex', 2, 14];
        yield 'new Discipline: 10' => ['brujah', 'dominate', 0, 10];
        yield 'clanless: current × 6' => ['caitiff', 'auspex', 2, 12];
    }

    #[DataProvider('disciplineCosts')]
    public function test_disciplines_cost_xp_by_clan(string $clan, string $discipline, int $current, int $cost): void
    {
        $sheet = ['clan' => ['clan' => $clan], 'disciplines' => [$discipline => $current]];
        $outcome = self::preset()->evaluate($sheet, [DemoPreset::set('d', "/disciplines/{$discipline}", $current + 1)], Phase::Advancement, ['xp' => 30]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(30 - $cost, $outcome->balances['xp']);
    }

    public function test_advancement_costs_attribute_x4_ability_new_3_virtue_x2_and_needs_xp_from_the_master(): void
    {
        $preset = self::preset();
        $sheet = ['attributes' => ['physical' => ['strength' => 3]], 'virtues' => ['courage' => 2]];
        $outcome = $preset->evaluate($sheet, [
            DemoPreset::set('s', '/attributes/physical/strength', 4),
            DemoPreset::set('a', '/abilities/knowledges/occult', 1),
            DemoPreset::set('v', '/virtues/courage', 3),
        ], Phase::Advancement, ['xp' => 20]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(20 - 12 - 3 - 4, $outcome->balances['xp']);

        $none = $preset->evaluate($sheet, [DemoPreset::set('s', '/attributes/physical/strength', 4)], Phase::Advancement);
        $this->assertContains('budget.insufficient', DemoPreset::ruleIds($none));
    }

    /** @return iterable<string, array{0: int, 1: int, 2: int, 3: int}> */
    public static function generations(): iterable
    {
        yield 'no Generation background: 13th' => [0, 13, 10, 1];
        yield 'Generation 3: 10th' => [3, 10, 13, 1];
        yield 'Generation 4: 9th' => [4, 9, 14, 2];
        yield 'Generation 5: 8th' => [5, 8, 15, 3];
    }

    #[DataProvider('generations')]
    public function test_the_generation_background_sets_generation_and_blood_pool(int $background, int $generation, int $pool, int $perTurn): void
    {
        $scope = self::preset()->mechanics()->scope(['backgrounds' => ['generation' => $background]], []);
        $this->assertSame($generation, $scope->derived('generation'));
        $this->assertSame($pool, $scope->derived('blood_pool_max'));
        $this->assertSame($perTurn, $scope->derived('blood_per_turn'));
    }

    public function test_the_blood_pool_cannot_exceed_its_generation_maximum(): void
    {
        $outcome = self::preset()->evaluate([], [DemoPreset::set('b', '/resources/blood', 11)]);
        $this->assertContains('resource.out_of_range', DemoPreset::ruleIds($outcome));
    }

    public function test_humanity_starts_at_conscience_plus_self_control_and_willpower_at_courage(): void
    {
        $preset = self::preset();
        $outcome = $preset->evaluate([], [
            DemoPreset::set('m', '/morality/path', 'humanity'),
            DemoPreset::set('c', '/virtues/conscience', 3), DemoPreset::set('s', '/virtues/self_control', 3), DemoPreset::set('k', '/virtues/courage', 4),
            DemoPreset::set('h', '/morality/rating', 7), DemoPreset::set('w', '/willpower/permanent', 4),
        ]);
        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $this->assertSame(0, $outcome->balances['virtues']);
        $this->assertSame(14, $outcome->balances['freebies'], 'one Humanity dot above 6 costs 1 freebie; Willpower 4 is free');
        $this->assertSame('conscience', $outcome->sheet['morality']['first_virtue'], 'Humanity uses Conscience');
        $this->assertSame('self_control', $outcome->sheet['morality']['second_virtue']);

        $this->assertTrue(self::step($outcome->sheet, 'finishing')['condition_met']);
        $low = $preset->evaluate([], [DemoPreset::set('m', '/morality/path', 'humanity'), DemoPreset::set('c', '/virtues/conscience', 3), DemoPreset::set('h', '/morality/rating', 3)]);
        $this->assertTrue($low->result->isValid(), 'an unfinished sheet can still be edited');
        $this->assertFalse(self::step($low->sheet, 'finishing')['condition_met'], 'Humanity 3 is below Conscience 3 + Self-Control 1');

        $path = $preset->evaluate([], [DemoPreset::set('m', '/morality/path', 'caine'), DemoPreset::set('v', '/morality/first_virtue', 'conviction')]);
        $this->assertTrue($path->result->isValid(), json_encode($path->result->toArray()));
    }

    public function test_seven_health_levels_give_the_wound_penalty_and_no_more_can_be_marked(): void
    {
        $preset = self::preset();
        $scope = $preset->mechanics()->scope(['resources' => ['bashing' => 2, 'lethal' => 1]], []);
        $this->assertSame(3, $scope->derived('damage_total'));
        $this->assertSame(-1, $scope->derived('wound_penalty'));
        $this->assertSame('Ferido', $scope->derived('health_state'));
        $this->assertSame(-5, $preset->mechanics()->scope(['resources' => ['aggravated' => 6]], [])->derived('wound_penalty'));

        $over = $preset->evaluate(['resources' => ['bashing' => 4, 'lethal' => 3]], [DemoPreset::set('a', '/resources/aggravated', 1)]);
        $this->assertContains('constraint.failed', DemoPreset::ruleIds($over));
    }

    /** @return iterable<string, array{0: list<int>, 1: int, 2: list<string>}> */
    public static function rolls(): iterable
    {
        yield 'two successes, a 1 cancels one' => [[8, 9, 1, 3], 1, []];
        yield 'no successes and a 1: botch' => [[1, 4, 5], 0, ['botch']];
        yield 'a success cancelled by a 1 is a failure, not a botch' => [[6, 1, 2], 0, []];
        yield 'difficulty 6 counts a 6' => [[6, 7, 10], 3, []];
    }

    /** @param list<int> $faces @param list<string> $flags */
    #[DataProvider('rolls')]
    public function test_a_d10_pool_of_attribute_plus_ability_counts_successes_cancels_with_1s_and_botches(array $faces, int $total, array $flags): void
    {
        $preset = self::preset();
        $count = count($faces);
        $scope = $preset->mechanics()->scope(['attributes' => ['physical' => ['dexterity' => $count - 1]], 'abilities' => ['talents' => ['brawl' => 1]]], []);
        $outcome = (new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema'])))
            ->roll('test', $scope, new SequenceRandom($faces), ['inputs' => ['attribute' => '/attributes/physical/dexterity', 'ability' => '/abilities/talents/brawl'], 'difficulty' => 6]);

        $this->assertSame($faces, $outcome->faces);
        $this->assertSame($total, $outcome->total);
        $this->assertSame($flags, $outcome->flags);
    }

    public function test_a_specialty_roll_counts_a_10_twice_and_difficulty_stays_within_2_to_10(): void
    {
        $preset = self::preset();
        $rolls = new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
        $scope = $preset->mechanics()->scope(['attributes' => ['mental' => ['wits' => 1]], 'abilities' => ['talents' => ['alertness' => 1]]], []);
        $inputs = ['attribute' => '/attributes/mental/wits', 'ability' => '/abilities/talents/alertness'];

        $this->assertSame(2, $rolls->roll('specialty_test', $scope, new SequenceRandom([10, 3]), ['inputs' => $inputs])->total);
        $this->assertSame(1, $rolls->roll('test', $scope, new SequenceRandom([10, 3]), ['inputs' => $inputs])->total);

        $this->expectExceptionMessage('roll.param_out_of_bounds');
        $rolls->roll('test', $scope, new SequenceRandom([5, 5]), ['inputs' => $inputs, 'difficulty' => 11]);
    }
}
