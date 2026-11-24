<?php

use App\Http\Middleware\EnsurePairedDeviceActive;
use App\Http\Problems\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/dev.php';
        },
    )
    // Channel authorization at /api/broadcasting/auth with the first-party session (ADR 0009).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA session cookies for requests from SANCTUM_STATEFUL_DOMAINS (ADR 0009).
        $middleware->statefulApi();
        // Revoked paired devices lose their session (ADR 0011).
        $middleware->api(append: [EnsurePairedDeviceActive::class, \App\Http\Middleware\TouchPresence::class]);
        // The API never redirects guests (there is no login page): <img> requests for campaign
        // images without a session get a 401 problem instead of a redirect error.
        $middleware->redirectGuestsTo(static fn (Request $request): ?string => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        ProblemRenderer::register($exceptions);
    })->create();
