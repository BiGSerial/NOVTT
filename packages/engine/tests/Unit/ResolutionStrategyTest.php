<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use InvalidArgumentException;
use Novtt\Engine\Infrastructure\CryptoRandomSource;
use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Resolution\DiceExpression;
use Novtt\Engine\Resolution\PoolRoll;
use Novtt\Engine\Resolution\PoolStrategy;
use Novtt\Engine\Resolution\PresetRolls;
use Novtt\Engine\Resolution\RollRejected;
use Novtt\Engine\Resolution\SumStrategy;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Tests\Support\SequenceRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Sprint 001 (preset engine) acceptance test 5: rolls are read by declarative strategies. */
final class ResolutionStrategyTest extends TestCase
{
    public function test_expression_1d20_plus_5_is_resolved_by_the_sum_strategy(): void
    {
        $roll = DiceExpression::parse('1d20+5', target: 15, criticalAtLeast: 20, fumbleAtMost: 1);
        $outcome = (new SumStrategy(new SequenceRandom([11])))->roll($roll);

        $this->assertSame('sum', $outcome->strategy);
        $this->assertSame([11], $outcome->faces);
        $this->assertSame(16, $outcome->total);
        $this->assertTrue($outcome->success);
        $this->assertSame([], $outcome->flags);
        $this->assertSame(['critical'], (new SumStrategy(new SequenceRandom([20])))->roll($roll)->flags);
        $this->assertSame(['fumble'], (new SumStrategy(new SequenceRandom([1])))->roll($roll)->flags);
    }

    public function test_expressions_with_several_groups_and_keep_highest(): void
    {
        $outcome = (new SumStrategy(new SequenceRandom([3, 5, 2, 1])))->roll(DiceExpression::parse('2d6 + 1d4 - 1d4 - 2'));
        $this->assertSame(3 + 5 + 2 - 1 - 2, $outcome->total);

        $kept = (new SumStrategy(new SequenceRandom([4, 1, 6, 3])))->roll(DiceExpression::parse('4d6kh3'));
        $this->assertSame([true, false, true, true], $kept->kept);
        $this->assertSame(13, $kept->total);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidExpressions(): iterable
    {
        foreach (['', 'd', '1d', '1d1', '101d6', '1d20+', 'system(1)', '1d20*2', '2d6kh3', str_repeat('1d6+', 20) . '1'] as $expression) {
            yield $expression === '' ? 'empty' : $expression => [$expression];
        }
    }

    #[DataProvider('invalidExpressions')]
    public function test_malformed_or_unbounded_expressions_are_rejected(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);
        DiceExpression::parse($expression);
    }

    public function test_pool_of_5d10_counts_successes_against_a_variable_difficulty_and_ones_cancel(): void
    {
        $faces = [10, 7, 6, 1, 3];
        $at6 = (new PoolStrategy(new SequenceRandom($faces)))->roll(new PoolRoll(5, 10, 6, cancelAtMost: 1));
        $this->assertSame(2, $at6->total, '3 successes (10, 7, 6) minus one cancelling 1');
        $this->assertTrue($at6->success);

        $at8 = (new PoolStrategy(new SequenceRandom($faces)))->roll(new PoolRoll(5, 10, 8, cancelAtMost: 1));
        $this->assertSame(0, $at8->total, '1 success (10) cancelled by the 1');
        $this->assertFalse($at8->success);

        $noCancel = (new PoolStrategy(new SequenceRandom($faces)))->roll(new PoolRoll(5, 10, 8));
        $this->assertSame(1, $noCancel->total, 'cancellation is a parameter, not a default');
    }

    public function test_pool_bonus_explosion_and_botch_are_parameters(): void
    {
        $bonus = (new PoolStrategy(new SequenceRandom([10, 6, 2])))->roll(new PoolRoll(3, 10, 6, bonusAtLeast: 10, bonusSuccesses: 1));
        $this->assertSame(3, $bonus->total);

        $explode = (new PoolStrategy(new SequenceRandom([10, 10, 4, 7])))->roll(new PoolRoll(2, 10, 6, explodeAtLeast: 10));
        $this->assertSame([10, 10, 4, 7], $explode->faces);
        $this->assertSame(2, $explode->trace['exploded']);

        $botch = (new PoolStrategy(new SequenceRandom([1, 3, 4])))->roll(new PoolRoll(3, 10, 6, cancelAtMost: 1, botchAtMost: 1));
        $this->assertSame(['botch'], $botch->flags);
        $this->assertSame(0, $botch->total);
    }

    public function test_explosions_are_bounded_by_the_dice_limit(): void
    {
        $outcome = (new PoolStrategy(new SequenceRandom(array_fill(0, 200, 10))))->roll(new PoolRoll(5, 10, 6, explodeAtLeast: 10));
        $this->assertCount(100, $outcome->faces);
    }

    public function test_preset_declared_pool_reads_attribute_plus_skill_from_the_sheet(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $sheet = ['attributes' => ['physical' => ['destreza' => 3]], 'skills' => ['furtividade' => 2]];
        $rolls = new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
        $scope = $preset->mechanics()->scope($sheet, []);
        $params = ['inputs' => ['attribute' => '/attributes/physical/destreza', 'skill' => '/skills/furtividade'], 'difficulty' => 7];

        $outcome = $rolls->roll('test', $scope, new SequenceRandom([8, 7, 2, 1, 10]), $params);
        $this->assertSame(5, count($outcome->faces), 'pool = destreza 3 + furtividade 2');
        $this->assertSame(7, $outcome->trace['difficulty']);
        $this->assertSame(3, $outcome->total, '8, 7, 10 succeed; 10 adds one; the 1 cancels one');

        $initiative = $rolls->roll('initiative', $scope, new SequenceRandom([4]));
        $this->assertSame(4 + 3 + 1, $initiative->total, '1d10 + derived initiative');
    }

    public function test_preset_declared_d20_check_with_advantage_and_bounded_target(): void
    {
        $preset = new DemoPreset('demo-heroico');
        $rolls = new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
        $scope = $preset->mechanics()->scope(['abilities' => ['agilidade' => 14], 'progress' => ['level' => 5]], []);
        $outcome = $rolls->roll('trained_check', $scope, new SequenceRandom([6, 17]), ['inputs' => ['ability' => '/abilities/agilidade'], 'edge' => 'advantage', 'target' => 20]);

        $this->assertSame([6, 17], $outcome->faces);
        $this->assertSame([false, true], $outcome->kept);
        $this->assertSame(17 + 2 + 3, $outcome->total, 'kept die + mod(14) + proficiency at level 5');
        $this->assertTrue($outcome->success);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}> */
    public static function rejectedRequests(): iterable
    {
        $inputs = ['attribute' => '/attributes/physical/destreza', 'skill' => '/skills/luta'];
        yield 'difficulty outside bounds' => ['test', ['inputs' => $inputs, 'difficulty' => 11], 'roll.param_out_of_bounds'];
        yield 'input outside declared targets' => ['test', ['inputs' => ['attribute' => '/resources/resolve', 'skill' => '/skills/luta']], 'roll.input_not_allowed'];
        yield 'missing input' => ['test', ['inputs' => ['attribute' => '/attributes/physical/destreza']], 'roll.input_required'];
        yield 'client tries to set faces' => ['test', ['inputs' => $inputs, 'faces' => 6], 'roll.unknown_param'];
        yield 'edge not declared for the roll' => ['initiative', ['edge' => 'advantage'], 'roll.edge_not_allowed'];
        yield 'unknown roll' => ['fireball', [], 'roll.unknown'];
    }

    #[DataProvider('rejectedRequests')]
    public function test_requests_cannot_exceed_the_declaration(string $roll, array $params, string $rule): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $rolls = new PresetRolls($preset->parts['rules'], new SchemaNavigator($preset->parts['sheet_schema']));
        try {
            $rolls->roll($roll, $preset->mechanics()->scope([], []), new SequenceRandom(array_fill(0, 20, 5)), $params);
            $this->fail('expected ' . $rule);
        } catch (RollRejected $e) {
            $this->assertSame($rule, $e->ruleId);
        }
    }

    public function test_production_randomness_is_the_cryptographic_source(): void
    {
        $outcome = (new PoolStrategy(new CryptoRandomSource()))->roll(new PoolRoll(20, 10, 6));
        $this->assertCount(20, $outcome->faces);
        foreach ($outcome->faces as $face) {
            $this->assertGreaterThanOrEqual(1, $face);
            $this->assertLessThanOrEqual(10, $face);
        }
    }
}
