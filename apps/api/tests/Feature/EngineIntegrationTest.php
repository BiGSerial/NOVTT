<?php

declare(strict_types=1);

namespace Tests\Feature;

use Novtt\Engine\Contracts\Clock;
use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Dice\DicePool;
use Novtt\Engine\Dice\DicePoolRoller;
use Novtt\Engine\Infrastructure\CryptoRandomSource;
use Novtt\Engine\Infrastructure\SystemClock;
use Tests\TestCase;

final class EngineIntegrationTest extends TestCase
{
    public function test_engine_ports_resolve_to_secure_adapters(): void
    {
        $this->assertInstanceOf(CryptoRandomSource::class, $this->app->make(RandomSource::class));
        $this->assertInstanceOf(SystemClock::class, $this->app->make(Clock::class));
        $this->assertSame($this->app->make(RandomSource::class), $this->app->make(RandomSource::class));
    }

    public function test_api_can_roll_through_the_engine(): void
    {
        $result = $this->app->make(DicePoolRoller::class)->roll(new DicePool(count: 5, faces: 10, threshold: 6));

        $this->assertCount(5, $result->dice);
        foreach ($result->dice as $face) {
            $this->assertGreaterThanOrEqual(1, $face);
            $this->assertLessThanOrEqual(10, $face);
        }
    }
}
