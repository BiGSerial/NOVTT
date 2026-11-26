<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\AuditTrail;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Models\PairedDevice;
use App\Models\PairingCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Device pairing (ADR 0011, SPEC-MVP-002 LT-08).
 *
 * 1. The new device asks for a code: it gets a 6-digit code to show and a claim secret it keeps.
 * 2. The master, signed in on another device, types the code (never the other way round).
 * 3. The new device claims with its secret and receives its own revocable session.
 * A code alone never grants access; only digests are stored; codes expire in two minutes.
 */
final class PairingController extends ApiController
{
    public const TTL_SECONDS = 120;

    public const MAX_CLAIMS = 60;

    public function __construct(private readonly AuditTrail $audit) {}

    public function create(Request $request): JsonResponse
    {
        $data = $this->input($request, ['device_label' => ['required', 'string', 'min:1', 'max:60']]);
        $claim = Str::random(43);
        $pairing = DB::transaction(function () use ($data, $claim, &$code): PairingCode {
            // Distinct among live codes so a master can never confirm someone else's device.
            do {
                $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
                $taken = PairingCode::query()->where('code_hash', hash('sha256', $code))->where('status', 'pending')->where('expires_at', '>', now())->exists();
            } while ($taken);

            return PairingCode::query()->create([
                'code_hash' => hash('sha256', $code),
                'claim_hash' => hash('sha256', $claim),
                'device_label' => trim($data['device_label']),
                'status' => 'pending',
                'claim_attempts' => 0,
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            ]);
        });

        return new JsonResponse(['pairing_id' => $pairing->getKey(), 'code' => $code, 'claim_secret' => $claim, 'expires_at' => $pairing->expires_at->format(DATE_RFC3339_EXTENDED)], 201);
    }

    /** The master (on an already authenticated, non-paired device) confirms the code. */
    public function confirm(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->forbidPairedDevice($request);
        $data = $this->input($request, ['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $pairing = DB::transaction(function () use ($actor, $data): PairingCode {
            $pairing = PairingCode::query()->where('code_hash', hash('sha256', $data['code']))->where('status', 'pending')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($pairing === null) {
                throw new ApiProblem(422, 'pairing.code_invalid');
            }
            $pairing->update(['status' => 'confirmed', 'user_id' => $actor->getKey()]);

            return $pairing;
        });

        return new JsonResponse(['pairing_id' => $pairing->getKey(), 'device_label' => $pairing->device_label, 'status' => 'confirmed']);
    }

    /** The new device polls with its claim secret; once confirmed it becomes a paired session. */
    public function claim(Request $request, string $pairingId): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new ApiProblem(400, 'auth.stateful_origin_required');
        }
        $data = $this->input($request, ['claim_secret' => ['required', 'string', 'size:43']]);
        $result = DB::transaction(function () use ($pairingId, $data): array {
            $pairing = Str::isUuid($pairingId) ? PairingCode::query()->lockForUpdate()->find($pairingId) : null;
            if ($pairing === null || $pairing->claim_attempts >= self::MAX_CLAIMS) {
                throw new ApiProblem(410, 'pairing.expired');
            }
            $pairing->update(['claim_attempts' => $pairing->claim_attempts + 1]);
            if (! hash_equals($pairing->claim_hash, hash('sha256', $data['claim_secret']))) {
                throw new ApiProblem(410, 'pairing.expired');
            }
            if ($pairing->status === 'pending' && $pairing->expires_at > now()) {
                return ['pending', null, null];
            }
            if ($pairing->status !== 'confirmed' || $pairing->expires_at <= now()) {
                throw new ApiProblem(410, 'pairing.expired');
            }
            $user = User::query()->findOrFail($pairing->user_id);
            $device = PairedDevice::query()->create(['user_id' => $user->getKey(), 'label' => $pairing->device_label, 'last_seen_at' => now(), 'revoked_at' => null]);
            $pairing->update(['status' => 'claimed', 'paired_device_id' => $device->getKey()]);
            $this->audit->record($user, null, 'device.paired', $device, ['label' => $device->label]);

            return ['claimed', $user, $device];
        });
        if ($result[0] === 'pending') {
            return new JsonResponse(['status' => 'pending'], 202);
        }
        [, $user, $device] = $result;
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('paired_device_id', $device->getKey());

        return new JsonResponse(['status' => 'claimed', 'me' => AccountController::me($user, $request)]);
    }

    public function devices(Request $request): JsonResponse
    {
        $this->forbidPairedDevice($request);

        return new JsonResponse(['data' => PairedDevice::query()->where('user_id', $this->actor($request)->getKey())->whereNull('revoked_at')->orderByDesc('created_at')->get()
            ->map(static fn (PairedDevice $d): array => ['id' => $d->getKey(), 'label' => $d->label, 'last_seen_at' => $d->last_seen_at?->format(DATE_RFC3339_EXTENDED), 'created_at' => $d->created_at?->format(DATE_RFC3339_EXTENDED)])->values()]);
    }

    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        $actor = $this->actor($request);
        $this->forbidPairedDevice($request);
        $device = Str::isUuid($deviceId) ? PairedDevice::query()->where('user_id', $actor->getKey())->whereNull('revoked_at')->find($deviceId) : null;
        if ($device === null) {
            throw ApiProblem::notFound();
        }
        $device->update(['revoked_at' => now()]);
        $this->audit->record($actor, null, 'device.revoked', $device);

        return new JsonResponse(null, 204);
    }

    private function forbidPairedDevice(Request $request): void
    {
        if ($request->hasSession() && $request->session()->get('paired_device_id') !== null) {
            throw new ApiProblem(403, 'device.not_allowed');
        }
    }
}
