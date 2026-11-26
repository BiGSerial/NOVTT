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
 * Ordem Paranormal RPG (livro básico) preset: the owner's handoff acceptance cases (attributes,
 * d20 selection), creation steps, holds from origin/class and the publication gate. Values marked
 * "a conferir" in the package are exercised as wired, not asserted as official.
 */
final class OrdemParanormalPresetTest extends TestCase
{
    private const PACKAGE = 'ordem-paranormal-rpg/livro-basico/0.1.1';

    private static function preset(): DemoPreset
    {
        return DemoPreset::package(self::PACKAGE);
    }

    public function test_the_package_is_engine_compatible_but_not_publishable(): void
    {
        $p = self::preset()->parts;
        $this->assertSame([], (new PresetCompatibility())->check($p['manifest'], $p['sheet_schema'], $p['sheet_ui'], $p['rules'], $p['compendium'])->toArray()['issues']);
        $this->assertNotSame([], (new PublicationGate())->check($p['manifest'], $p['compendium'])->toArray()['issues'], 'publication stays blocked until legal review');
        $this->assertFalse($p['manifest']['publication']['public']);
    }

    /** @return iterable<string, array{0: array<string, int>, 1: bool}> handoff tests/creation-attribute-cases.json */
    public static function attributeCases(): iterable
    {
        yield 'AGI 3 FOR 2 INT 2' => [['agi' => 3, 'for' => 2, 'int' => 2, 'pre' => 1, 'vig' => 1], true];
        yield 'one zero gives a point' => [['agi' => 3, 'for' => 3, 'int' => 2, 'pre' => 1, 'vig' => 0], true];
        yield 'above 3 at creation' => [['agi' => 4, 'for' => 1, 'int' => 1, 'pre' => 1, 'vig' => 1], false];
        yield 'two zeros' => [['agi' => 0, 'for' => 0, 'int' => 3, 'pre' => 3, 'vig' => 3], false];
        yield 'all at 3' => [['agi' => 3, 'for' => 3, 'int' => 3, 'pre' => 3, 'vig' => 3], false];
    }

    /** @param array<string, int> $attributes */
    #[DataProvider('attributeCases')]
    public function test_attributes_start_at_1_with_4_points_one_zero_for_an_extra_point_and_3_at_most(array $attributes, bool $valid): void
    {
        asort($attributes); // lower first so the extra point exists before it is spent
        $ops = [];
        foreach ($attributes as $attribute => $value) {
            $ops[] = DemoPreset::set($attribute, "/atributos/{$attribute}", $value);
        }
        $outcome = self::preset()->evaluate([], $ops);

        $this->assertSame($valid, $outcome->result->isValid(), json_encode($outcome->result->toArray()));
        if ($valid) {
            $this->assertSame(0, $outcome->balances['atributos'], 'all points used');
        }
    }

    /** @return iterable<string, array{0: int, 1: list<int>, 2: int}> handoff tests/attribute-roll-cases.json */
    public static function rollCases(): iterable
    {
        yield 'attribute 3: best of 3' => [3, [6, 17, 4], 17];
        yield 'attribute 2: best of 2' => [2, [1, 19], 19];
        yield 'attribute 0: worst of 2' => [0, [4, 17], 4];
        yield 'attribute 1: one die' => [1, [12], 12];
    }

    /** @param list<int> $faces */
    #[DataProvider('rollCases')]
    public function test_an_attribute_test_rolls_a_d20_per_point_keeping_the_best_or_two_keeping_the_worst_at_0(int $value, array $faces, int $expected): void
    {
        $preset = self::preset();
        $scope = $preset->mechanics()->scope(['atributos' => ['agi' => $value]], []);
        $outcome = (new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema'])))
            ->roll('atributo', $scope, new SequenceRandom($faces), ['inputs' => ['atributo' => '/atributos/agi']]);

        $this->assertSame($faces, $outcome->faces);
        $this->assertSame($expected, $outcome->total);
    }

    public function test_a_skill_test_uses_the_linked_attribute_dice_and_the_training_bonus(): void
    {
        $preset = self::preset();
        $scope = $preset->mechanics()->scope(['atributos' => ['agi' => 2], 'pericias' => ['furtividade' => 1]], []);
        $outcome = (new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema'])))
            ->roll('pericia_furtividade', $scope, new SequenceRandom([8, 14]), ['target' => 20]);

        $this->assertSame(19, $outcome->total, 'best d20 (14) + treinado (+5)');
        $this->assertFalse($outcome->success);
    }

    public function test_origin_and_class_train_their_skills_for_free_and_the_class_sets_the_budget(): void
    {
        $preset = self::preset();
        $outcome = $preset->evaluate([], [
            DemoPreset::set('i', '/atributos/int', 2),
            DemoPreset::set('o', '/origem/origem', 'academico'),
            DemoPreset::set('c', '/classe/classe', 'ocultista'),
        ]);

        $this->assertTrue($outcome->result->isValid(), json_encode($outcome->result->toArray()));
        $skills = $outcome->sheet['pericias'];
        foreach (['ciencias', 'investigacao', 'ocultismo', 'vontade'] as $trained) {
            $this->assertSame(1, $skills[$trained], "{$trained} trained by origin or class");
        }
        $this->assertSame(5, $outcome->balances['pericias'], 'ocultista 3 + INT 2 still to choose, held skills are free');
        $this->assertTrue($outcome->sheet['proficiencias']['armas_simples']);
    }

    public function test_the_combatente_picks_one_skill_of_each_pair_and_others_cannot(): void
    {
        $preset = self::preset();
        $combatente = $preset->evaluate([], [DemoPreset::set('c', '/classe/classe', 'combatente'), DemoPreset::set('a', '/classe/pericia_ataque', 'pontaria')]);
        $this->assertTrue($combatente->result->isValid(), json_encode($combatente->result->toArray()));
        $this->assertSame(1, $combatente->sheet['pericias']['pontaria']);

        $especialista = $preset->evaluate([], [DemoPreset::set('c', '/classe/classe', 'especialista'), DemoPreset::set('a', '/classe/pericia_ataque', 'luta')]);
        $this->assertContains('choice.prerequisite_unmet', DemoPreset::ruleIds($especialista));
    }

    public function test_a_trail_needs_its_class_and_nex_10(): void
    {
        $preset = self::preset();
        $early = $preset->evaluate([], [DemoPreset::set('c', '/classe/classe', 'especialista'), DemoPreset::set('t', '/classe/trilha', 'infiltrador')]);
        $this->assertContains('choice.prerequisite_unmet', DemoPreset::ruleIds($early));

        $availability = $preset->mechanics()->choiceAvailability(['classe' => ['classe' => 'especialista'], 'progresso' => ['nex_nivel' => 2]], []);
        $trail = array_values(array_filter($availability, static fn (array $c): bool => $c['id'] === 'trilha'))[0];
        $open = array_map(static fn (array $o): string => (string) $o['value'], array_filter($trail['options'], static fn (array $o): bool => $o['available']));
        $this->assertSame(['atirador_de_elite', 'infiltrador', 'medico_de_campo', 'negociador', 'tecnico'], array_values($open));
    }

    public function test_nex_shows_as_a_percentage_up_to_99_and_resources_follow_the_class_table(): void
    {
        $preset = self::preset();
        $sheet = ['classe' => ['classe' => 'combatente'], 'atributos' => ['vig' => 2, 'pre' => 1], 'progresso' => ['nex_nivel' => 20]];
        $scope = $preset->mechanics()->scope($sheet, []);
        $this->assertSame(99, $scope->derived('nex'));
        $this->assertSame(1, $preset->mechanics()->scope(['progresso' => ['nex_nivel' => 1]], [])->derived('pe_por_rodada'));

        $start = $preset->mechanics()->scope(['classe' => ['classe' => 'combatente'], 'atributos' => ['vig' => 2]], []);
        $this->assertSame(22, $start->derived('pv_max'), 'table value as wired (a conferir): 20 + VIG');
    }

    public function test_nex_rises_one_step_per_step_granted_by_the_master(): void
    {
        $preset = self::preset();
        $this->assertContains('progression.level_not_earned', DemoPreset::ruleIds($preset->evaluate([], [DemoPreset::set('n', '/progresso/nex_nivel', 2)], Phase::Advancement)));
        $granted = $preset->evaluate([], [DemoPreset::set('n', '/progresso/nex_nivel', 2)], Phase::Advancement, ['marcos_nex' => 1]);
        $this->assertNotContains('progression.level_not_earned', DemoPreset::ruleIds($granted));
    }
}
