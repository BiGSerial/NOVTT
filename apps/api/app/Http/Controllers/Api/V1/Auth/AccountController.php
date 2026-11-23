<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Email + password accounts through the first-party session (ADR 0009, SPEC-MVP-002 LT-01).
 * Errors never reveal whether an email is registered. Account recovery is out of the PoC.
 */
final class AccountController extends ApiController
{
    public function register(Request $request): JsonResponse
    {
        $this->requireSession($request);
        $data = $this->input($request, [
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'min:10', 'max:200'],
        ]);
        $email = Str::lower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            // Same shape as a validation failure on any other field: no account enumeration oracle
            // beyond what the unique email inherently implies.
            throw new ApiProblem(422, 'account.unavailable');
        }
        $user = User::query()->create(['name' => trim($data['name']), 'email' => $email, 'password' => Hash::make($data['password'])]);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return new JsonResponse(self::me($user, $request), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->requireSession($request);
        $data = $this->input($request, [
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        if (! Auth::guard('web')->attempt(['email' => Str::lower(trim($data['email'])), 'password' => $data['password']])) {
            throw new ApiProblem(401, 'auth.invalid_credentials');
        }
        $request->session()->regenerate();
        $request->session()->forget('paired_device_id');

        return new JsonResponse(self::me(Auth::guard('web')->user(), $request));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new JsonResponse(null, 204);
    }

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(self::me($this->actor($request), $request));
    }

    /** @return array<string, mixed> */
    public static function me(User $user, Request $request): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            // Paired tablets act for the master but cannot manage accounts or devices.
            'paired_device_id' => $request->hasSession() ? $request->session()->get('paired_device_id') : null,
        ];
    }

    private function requireSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiProblem(400, 'auth.stateful_origin_required');
        }
    }
}
