<?php

declare(strict_types=1);

namespace App\Application;

use App\Http\Problems\ApiProblem;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs a command exactly once per (actor, scope, key). MUST be called inside the command's
 * transaction so the stored outcome commits or rolls back together with the state change.
 * Concurrent duplicates serialize on the unique index: the second waits, then replays.
 */
final class Idempotency
{
    /**
     * @param Closure(): array{0: int, 1: array<string, mixed>} $command returns [status, body]
     * @return array{0: int, 1: array<string, mixed>, 2: bool} [status, body, replayed]
     */
    public function run(User $actor, string $scope, string $key, string $payloadHash, Closure $command): array
    {
        if (!Str::isUuid($key)) {
            throw new ApiProblem(400, 'idempotency.key_invalid');
        }
        $inserted = DB::table('idempotency_records')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'actor_user_id' => $actor->getKey(),
            'scope' => $scope,
            'idempotency_key' => $key,
            'payload_hash' => $payloadHash,
            'response_status' => 0,
            'response_body' => '{}',
            'created_at' => now(),
        ]);
        $record = DB::table('idempotency_records')
            ->where(['actor_user_id' => $actor->getKey(), 'scope' => $scope, 'idempotency_key' => $key])
            ->lockForUpdate()
            ->first();

        if ($inserted === 0) {
            if ($record->payload_hash !== $payloadHash) {
                throw ApiProblem::conflict('idempotency.key_reused');
            }
            if ((int) $record->response_status > 0) {
                return [(int) $record->response_status, json_decode((string) $record->response_body, true, 64, JSON_THROW_ON_ERROR), true];
            }
        }

        [$status, $body] = $command();
        DB::table('idempotency_records')->where('id', $record->id)->update([
            'response_status' => $status,
            'response_body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return [$status, $body, false];
    }
}
