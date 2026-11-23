<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading (N+1), on attributes outside #[Fillable] (mass assignment)
        // and on reading attributes that were not selected.
        Model::shouldBeStrict();

        // Per authenticated user (falls back to IP before authentication resolves).
        RateLimiter::for('novtt-api', static fn (Request $request): Limit => Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('novtt-imports', static fn (Request $request): Limit => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Token guessing: limit by user and by IP independently.
        RateLimiter::for('novtt-invitations', static fn (Request $request): array => [
            Limit::perMinute(10)->by('u:' . ($request->user()?->getAuthIdentifier() ?? 'guest')),
            Limit::perMinute(30)->by('ip:' . $request->ip()),
        ]);
    }
}
