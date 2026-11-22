<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Novtt\Engine\Contracts\Clock;
use Novtt\Engine\Contracts\RandomSource;
use Novtt\Engine\Infrastructure\CryptoRandomSource;
use Novtt\Engine\Infrastructure\SystemClock;

/**
 * Wires the framework-independent engine ports to their infrastructure adapters.
 * Official randomness must stay cryptographically secure (ADR 0002).
 */
final class EngineServiceProvider extends ServiceProvider
{
    public array $singletons = [
        RandomSource::class => CryptoRandomSource::class,
        Clock::class => SystemClock::class,
    ];
}
