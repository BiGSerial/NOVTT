<?php

declare(strict_types=1);

namespace App\Live;

use App\Http\Problems\ApiProblem;
use App\Models\OfficialRoll;
use App\Models\RollRequest;
use Closure;
use Illuminate\Support\Facades\DB;
use Novtt\Engine\Dice\RollResult;
use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\OfficialRollOutcome;
use Novtt\Engine\Rolls\OfficialRollStore;
use Novtt\Engine\Rolls\RollAuthorization;
use Novtt\Engine\Rolls\RollStatus;

/**
 * PostgreSQL implementation of the engine's OfficialRollStore (SPEC-003 OR-03, OR-06, OR-07).
 * Serializes on the session row and then the request row, scopes idempotency keys by actor,
 * and commits the outcome, consumed attempt and session events in one transaction.
 *
 * `$record` persists the outcome and appends events; it runs inside the same transaction.
 */
final class PgOfficialRollStore implements OfficialRollStore
{
    /** @param Closure(RollRequest, OfficialRollOutcome, string): void $record */
    public function __construct(private readonly Closure $record) {}

    public function executeAtomically(string $actorId, AuthorizedRoll $command, string $payloadFingerprint, callable $execute): OfficialRollOutcome
    {
        return DB::transaction(function () use ($actorId, $command, $payloadFingerprint, $execute): OfficialRollOutcome {
            $request = RollRequest::query()->find($command->requestId);
            if ($request === null || $request->roller_user_id !== $actorId) {
                throw ApiProblem::notFound();
            }
            // Lock order: session, then request (the same order as every other session command).
            $status = DB::table('game_sessions')->where('id', $request->session_id)->lockForUpdate()->value('status');
            $existing = OfficialRoll::query()->where(['actor_user_id' => $actorId, 'idempotency_key' => $command->idempotencyKey])->first();
            if ($existing !== null) {
                if (! hash_equals($existing->payload_fingerprint, $payloadFingerprint)) {
                    throw ApiProblem::conflict('idempotency.key_reused');
                }

                return new OfficialRollOutcome($existing->roll_request_id, $existing->idempotency_key, new RollResult($existing->results, $existing->successes), 0, $existing->attempt);
            }
            if ($status !== 'active') {
                throw ApiProblem::conflict($status === 'ended' ? 'session.ended' : 'session.paused');
            }
            $request = RollRequest::query()->lockForUpdate()->findOrFail($command->requestId);
            $authorization = new RollAuthorization(
                requestId: $request->getKey(),
                minDice: $request->min_dice,
                maxDice: $request->max_dice,
                attemptsAllowed: $request->attempts_allowed,
                attemptsConsumed: $request->attempts_consumed,
                revision: $request->revision,
                allowsPlayerChoice: $request->allows_choice,
                status: RollStatus::from($request->status),
                expiresAt: $request->expires_at,
                faces: $request->faces,
                threshold: $request->threshold,
                successesPerDie: $request->successes_per_die,
            );
            $outcome = $execute($authorization);
            ($this->record)($request, $outcome, $payloadFingerprint);

            return $outcome;
        });
    }
}
