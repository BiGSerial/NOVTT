<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse presence: an authenticated request marks the user as seen, at most once a minute
 * (one cheap UPDATE, no model events). "Online" = seen in the last 2 minutes; only fellow
 * campaign members can read it (MeController::companions).
 */
final class TouchPresence
{
    public const INTERVAL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        if (!$user instanceof User) {
            return $response;
        }
        // The column may not be loaded on this instance (e.g. a user selected with few columns).
        $seen = array_key_exists('last_seen_at', $user->getAttributes()) ? $user->last_seen_at : null;
        if ($seen === null || $seen->getTimestamp() < time() - self::INTERVAL_SECONDS) {
            DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => now()]);
        }

        return $response;
    }
}
