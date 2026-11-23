<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problems\ApiProblem;
use App\Models\PairedDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A paired device's session works only while its device row is active (ADR 0011). Revoking a
 * device ends its session on the next request, including websocket channel authorization.
 */
final class EnsurePairedDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->hasSession() ? $request->session()->get('paired_device_id') : null;
        if ($deviceId !== null) {
            $device = PairedDevice::query()->find($deviceId);
            if ($device === null || $device->revoked_at !== null || $device->user_id !== Auth::guard('web')->id()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                throw new ApiProblem(401, 'device.revoked');
            }
            if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
                $device->update(['last_seen_at' => now()]);
            }
        }

        return $next($request);
    }
}
