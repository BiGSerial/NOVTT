<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Resolution\RollRejected;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Tests\Support\SequenceRandom;
use PHPUnit\Framework\TestCase;

/** Rolls that read the sheet: critical range, imposed advantage/disadvantage, dice faces. */
final class RollFormulaOptionsTest extends TestCase
{
    /** demo-heroico with a test roll: crit at 20 − (vocation lamina ? 1 : 0); disadvantage while level ≥ 5 (stand-in for a condition); faces = 6 + 2 × level (capped by the bounds). */
    private static function rolls(array $roll): array
    {
        $preset = new DemoPreset('demo-heroico');
        $rules = $preset->parts['rules'];
        $rules['rolls'][] = ['id' => 'probe', 'label' => 'Probe', 'strategy' => 'sum', 'dice' => '1d20'] + $roll;

        return [$preset, new PresetRolls($rules, new SchemaNavigator($preset->parts['sheet_schema']))];
    }

    public function test_the_critical_range_can_come_from_the_sheet(): void
    {
        [$preset, $rolls] = self::rolls(['critical_at_least' => ['op' => 'if', 'args' => [['op' => 'compare', 'cmp' => 'eq', 'args' => [['ref' => '/calling/vocation'], ['const' => 'lamina']]], ['const' => 19], ['const' => 20]]]]);
        $lamina = $preset->mechanics()->scope(['calling' => ['vocation' => 'lamina']], []);
        $arcano = $preset->mechanics()->scope(['calling' => ['vocation' => 'arcano']], []);
        $this->assertContains('critical', $rolls->roll('probe', $lamina, new SequenceRandom([19]))->flags);
        $this->assertNotContains('critical', $rolls->roll('probe', $arcano, new SequenceRandom([19]))->flags);
    }

    public function test_imposed_disadvantage_applies_and_cancels_a_requested_advantage(): void
    {
        [$preset, $rolls] = self::rolls(['edge' => ['extra_dice' => 1, 'force' => ['op' => 'if', 'args' => [['op' => 'compare', 'cmp' => 'ge', 'args' => [['ref' => '/progress/level'], ['const' => 5]]], ['const' => 'disadvantage'], ['const' => 'normal']]]]]);
        $tired = $preset->mechanics()->scope(['progress' => ['level' => 5]], []);
        $this->assertSame(3, $rolls->roll('probe', $tired, new SequenceRandom([3, 17]))->total, 'disadvantage keeps the lower');
        $this->assertSame([3], $rolls->roll('probe', $tired, new SequenceRandom([3, 17]), ['edge' => 'advantage'])->faces, 'advantage + disadvantage = one die');
        $fresh = $preset->mechanics()->scope(['progress' => ['level' => 1]], []);
        $this->assertSame(17, $rolls->roll('probe', $fresh, new SequenceRandom([3, 17]), ['edge' => 'advantage'])->total);
    }

    public function test_faces_can_come_from_the_sheet_and_are_bounded(): void
    {
        [$preset, $rolls] = self::rolls(['faces' => ['op' => 'add', 'args' => [['const' => 6], ['op' => 'multiply', 'args' => [['ref' => '/progress/level'], ['const' => 2]]]]]]);
        $spec = $rolls->prepare('probe', $preset->mechanics()->scope(['progress' => ['level' => 3]], []));
        $this->assertSame(12, $spec->groups[0]->faces);
        [$preset2, $bad] = self::rolls(['faces' => ['const' => 1]]);
        $this->expectException(RollRejected::class);
        $bad->prepare('probe', $preset2->mechanics()->scope([], []));
    }
}
