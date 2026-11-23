<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Demo\DemoMode;
use App\Http\Problems\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Demo endpoints do not exist outside local demo mode (404, same as any unknown route). */
final class EnsureDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!DemoMode::enabled()) {
            throw ApiProblem::notFound();
        }

        return $next($request);
    }
}
