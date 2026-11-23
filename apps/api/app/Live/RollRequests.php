<?php

declare(strict_types=1);

namespace App\Live;

use App\Application\AuditTrail;
use App\Domain\CampaignCharacterStatus;
use App\Http\Problems\ApiProblem;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\GameSession;
use App\Models\OfficialRoll;
use App\Models\RollRequest;
use App\Models\RollSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Novtt\Engine\Contracts\Clock;
use Novtt\Engine\Dice\DicePoolRoller;
use Novtt\Engine\Rolls\AuthorizedRoll;
use Novtt\Engine\Rolls\OfficialRollOutcome;
use Novtt\Engine\Rolls\OfficialRollService;
use Novtt\Engine\Rolls\RollEvaluator;
use Novtt\Engine\Rolls\RollPolicy;
use Novtt\Engine\Rolls\RollPolicyViolation;
use Novtt\Engine\Rolls\RollReading;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Master roll requests and official rolls (SPEC-MVP-002 LT-04, D1–D3).
 *
 * The master chooses the dice of a request; the server reads the combination from the official
 * sheet; the roller only chooses a dice count when the request allows it. Randomness, reading and
 * outcome are computed here, never by a client.
 */
final readonly class RollRequests
{
    /** Dice the clients can draw (3D dice, PR #13/#16). */
    public const FACES = [4, 6, 8, 10, 12, 20, 100];

    public const MAX_DICE = 12;

    public function __construct(
        private SessionAccess $access,
        private SessionStream $stream,
        private SheetCombination $combination,
        private AuditTrail $audit,
        private DicePoolRoller $roller,
        private Clock $clock,
    ) {}

    /**
     * Validated request data: paths, modifier, dice{faces,count_mode,count?,reading,threshold?,
     * successes_per_die?,target?}, allows_choice, attempts, secret, expires_in_seconds.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $master, string $sessionId, array $data, ?string $suggestionId = null): RollRequest
    {
        return DB::transaction(function () use ($master, $sessionId, $data, $suggestionId): RollRequest {
            $session = $this->access->lockOpen($sessionId, requireActive: true);
            $this->access->requireMaster($master, $session->campaign_id);
            $character = CampaignCharacter::query()->where('campaign_id', $session->campaign_id)->find($data['campaign_character_id']);
            if ($character === null || $character->status === CampaignCharacterStatus::Archived) {
                throw self::invalid('roll.character_unavailable', '/campaign_character_id');
            }
            $components = $this->combination->resolve($character->preset_version_id, (array) $character->sheet, $data['paths']);
            $roller = $character->custodian_user_id ?? $character->player_user_id;
            $request = $this->persistRequest($session, $master, $roller, $components, $data, characterId: $character->getKey(), npcId: null);

            if ($suggestionId !== null) {
                $updated = RollSuggestion::query()->whereKey($suggestionId)->where(['session_id' => $session->getKey(), 'status' => 'pending', 'campaign_character_id' => $character->getKey()])
                    ->update(['status' => 'accepted', 'roll_request_id' => $request->getKey(), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw self::invalid('suggestion.unavailable', '/suggestion_id');
                }
                $this->stream->append($session, 'suggestion.resolved', ['id' => $suggestionId, 'status' => 'accepted', 'roll_request_id' => $request->getKey()], Audience::master(), Audience::user($character->player_user_id));
            }
            $this->audit->record($master, $session->campaign_id, 'roll_request.created', $request, ['secret' => $request->secret]);
            $this->stream->append($session, 'roll.requested', RollViews::request($request, $character->name), ...$this->audiences($request));

            return $request;
        });
    }

    /** Executes a request as its roller (player, custodian or master for NPC requests). */
    public function execute(User $actor, string $requestId, ?int $diceCount, int $expectedRevision, string $key): OfficialRoll
    {
        $request = RollRequest::query()->find($requestId);
        if ($request === null || $request->roller_user_id !== $actor->getKey()) {
            throw ApiProblem::notFound();
        }
        $store = new PgOfficialRollStore(fn (RollRequest $locked, OfficialRollOutcome $outcome, string $fingerprint) => $this->record($actor, $locked, $outcome, $fingerprint, $key));
        $service = new OfficialRollService($store, new RollPolicy($this->clock), $this->roller);
        try {
            $service->execute($actor->getKey(), new AuthorizedRoll($requestId, $diceCount ?? $request->max_dice, $expectedRevision, $key));
        } catch (RollPolicyViolation $violation) {
            throw ApiProblem::conflict(self::violationCode($violation->getMessage()), $request->fresh()?->revision);
        }

        return OfficialRoll::query()->where(['actor_user_id' => $actor->getKey(), 'idempotency_key' => $key])->firstOrFail();
    }

    /**
     * The master rolls for an NPC: request + roll in one command, secret unless stated otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    public function rollForNpc(User $master, string $sessionId, array $data, string $key): OfficialRoll
    {
        $replay = OfficialRoll::query()->where(['actor_user_id' => $master->getKey(), 'idempotency_key' => $key])->first();
        if ($replay !== null) {
            // Same actor + key: the committed roll is the answer. (A different NPC command reusing
            // the key is indistinguishable here because the request is created by this command.)
            return $replay;
        }

        return DB::transaction(function () use ($master, $sessionId, $data, $key): OfficialRoll {
            $session = $this->access->lockOpen($sessionId, requireActive: true);
            $this->access->requireMaster($master, $session->campaign_id);
            $npc = CampaignNpc::query()->where('campaign_id', $session->campaign_id)->whereNull('archived_at')->find($data['campaign_npc_id']);
            if ($npc === null) {
                throw self::invalid('roll.npc_unavailable', '/campaign_npc_id');
            }
            $components = $data['paths'] === [] ? [] : $this->combination->resolve($npc->preset_version_id, (array) $npc->sheet, $data['paths']);
            $data['secret'] ??= true;
            $data['attempts'] = 1;
            $data['allows_choice'] = false;
            $request = $this->persistRequest($session, $master, $master->getKey(), $components, $data, characterId: null, npcId: $npc->getKey());
            $this->audit->record($master, $session->campaign_id, 'roll_request.created', $request, ['secret' => $request->secret, 'npc' => true]);

            return $this->execute($master, $request->getKey(), null, $request->revision, $key);
        });
    }

    public function reveal(User $master, string $rollId): OfficialRoll
    {
        return DB::transaction(function () use ($master, $rollId): OfficialRoll {
            $roll = OfficialRoll::query()->find($rollId) ?? throw ApiProblem::notFound();
            $session = $this->access->lockOpen($roll->session_id);
            $this->access->requireMaster($master, $session->campaign_id);
            $roll = OfficialRoll::query()->lockForUpdate()->findOrFail($rollId);
            if (! $roll->secret || $roll->revealed_at !== null) {
                return $roll;
            }
            $roll->update(['revealed_at' => now()]);
            $request = RollRequest::query()->findOrFail($roll->roll_request_id);
            $this->audit->record($master, $session->campaign_id, 'official_roll.revealed', $roll);
            $isNpc = $request->campaign_npc_id !== null;
            $this->stream->append($session, 'roll.revealed', RollViews::roll($roll, $request, $this->subjectName($request), withNumbers: ! $isNpc), Audience::table());

            return $roll;
        });
    }

    public function cancel(User $master, string $requestId): RollRequest
    {
        return $this->masterUpdate($master, $requestId, 'roll_request.cancelled', function (RollRequest $r): void {
            if ($r->status !== 'OPEN') {
                throw ApiProblem::conflict('roll.request_not_open', $r->revision);
            }
            $r->update(['status' => 'CANCELLED', 'revision' => $r->revision + 1]);
        });
    }

    /** Grants one more attempt (GM reroll, SPEC-003 OR-09), reopening an exhausted request. */
    public function grantAttempt(User $master, string $requestId): RollRequest
    {
        return $this->masterUpdate($master, $requestId, 'roll_request.attempt_granted', function (RollRequest $r): void {
            if (! in_array($r->status, ['OPEN', 'EXHAUSTED'], true) || $r->attempts_allowed >= 10) {
                throw ApiProblem::conflict('roll.cannot_grant', $r->revision);
            }
            $r->update(['status' => 'OPEN', 'attempts_allowed' => $r->attempts_allowed + 1, 'revision' => $r->revision + 1]);
        });
    }

    public function subjectName(RollRequest $r): string
    {
        return $r->campaign_character_id !== null
            ? (string) CampaignCharacter::query()->whereKey($r->campaign_character_id)->value('name')
            : (string) CampaignNpc::query()->whereKey($r->campaign_npc_id)->value('name');
    }

    /** @return list<Audience> */
    public function audiences(RollRequest $r): array
    {
        return $r->secret ? [Audience::master(), Audience::user($r->roller_user_id)] : [Audience::table()];
    }

    /**
     * @param  list<array{path: string, label: string, value: int}>  $components
     * @param  array<string, mixed>  $data
     */
    private function persistRequest(GameSession $session, User $master, string $rollerId, array $components, array $data, ?string $characterId, ?string $npcId): RollRequest
    {
        $dice = $data['dice'];
        $faces = (int) $dice['faces'];
        $sum = array_sum(array_column($components, 'value')) + (int) ($data['modifier'] ?? 0);
        $perTable = $faces === 100 ? intdiv(self::MAX_DICE, 2) : self::MAX_DICE;
        if ($dice['count_mode'] === 'pool') {
            $count = max(1, min($perTable, $sum));
            $bonus = 0;
        } else {
            $count = (int) ($dice['count'] ?? 1);
            if ($count < 1 || $count > $perTable) {
                throw self::invalid('roll.dice_count_invalid', '/dice/count');
            }
            $bonus = $sum;
        }
        $reading = RollReading::from($dice['reading']);
        $threshold = (int) ($dice['threshold'] ?? ($reading === RollReading::SUCCESSES ? intdiv($faces, 2) + 1 : 1));
        if ($threshold < 1 || $threshold > $faces) {
            throw self::invalid('roll.threshold_invalid', '/dice/threshold');
        }
        $choice = (bool) ($data['allows_choice'] ?? false) && $dice['count_mode'] === 'pool';
        $expires = isset($data['expires_in_seconds']) ? now()->addSeconds((int) $data['expires_in_seconds']) : null;

        return RollRequest::query()->create([
            'campaign_id' => $session->campaign_id,
            'session_id' => $session->getKey(),
            'campaign_character_id' => $characterId,
            'campaign_npc_id' => $npcId,
            'roller_user_id' => $rollerId,
            'requested_by' => $master->getKey(),
            'label' => ($data['label'] ?? '') ?: implode(' + ', array_column($components, 'label')) ?: "d{$faces}",
            'components' => $components,
            'modifier' => (int) ($data['modifier'] ?? 0),
            'faces' => $faces,
            'count_mode' => $dice['count_mode'],
            'reading' => $reading->value,
            'threshold' => $threshold,
            'successes_per_die' => (int) ($dice['successes_per_die'] ?? 1),
            'bonus' => $bonus,
            'target' => isset($dice['target']) ? (int) $dice['target'] : null,
            'min_dice' => $choice ? 1 : $count,
            'max_dice' => $count,
            'allows_choice' => $choice,
            'attempts_allowed' => (int) ($data['attempts'] ?? 1),
            'attempts_consumed' => 0,
            'secret' => (bool) ($data['secret'] ?? false),
            'status' => 'OPEN',
            'revision' => 0,
            'expires_at' => $expires,
        ]);
    }

    /** Runs inside the store's transaction, after the engine rolled. */
    private function record(User $actor, RollRequest $request, OfficialRollOutcome $outcome, string $fingerprint, string $key): void
    {
        $evaluation = RollEvaluator::evaluate(
            $outcome->result->dice,
            RollReading::from($request->reading),
            $request->bonus,
            $request->threshold,
            $request->successes_per_die,
            $request->target,
        );
        $roll = OfficialRoll::query()->create([
            'roll_request_id' => $request->getKey(),
            'session_id' => $request->session_id,
            'actor_user_id' => $actor->getKey(),
            'idempotency_key' => $key,
            'payload_fingerprint' => $fingerprint,
            'attempt' => $outcome->attemptsConsumed,
            'dice_count' => count($outcome->result->dice),
            'results' => $outcome->result->dice,
            'total' => $evaluation->total,
            'successes' => $evaluation->successes,
            'succeeded' => $evaluation->succeeded,
            'animation_seed' => random_int(1, 0xFFFFFFFF),
            'secret' => $request->secret,
            'revealed_at' => null,
            'created_at' => now(),
        ]);
        $request->update([
            'attempts_consumed' => $outcome->attemptsConsumed,
            'revision' => $outcome->revision,
            'status' => $outcome->attemptsConsumed >= $request->attempts_allowed ? 'EXHAUSTED' : 'OPEN',
        ]);
        $session = GameSession::query()->lockForUpdate()->findOrFail($request->session_id);
        $this->audit->record($actor, $session->campaign_id, 'official_roll.resolved', $roll, ['attempt' => $roll->attempt, 'secret' => $roll->secret]);
        $this->stream->append($session, 'roll.resolved', RollViews::roll($roll, $request, $this->subjectName($request)), ...$this->audiences($request));
    }

    /** @param callable(RollRequest): void $change */
    private function masterUpdate(User $master, string $requestId, string $action, callable $change): RollRequest
    {
        return DB::transaction(function () use ($master, $requestId, $action, $change): RollRequest {
            $request = RollRequest::query()->find($requestId) ?? throw ApiProblem::notFound();
            $session = $this->access->lockOpen($request->session_id);
            $this->access->requireMaster($master, $session->campaign_id);
            $request = RollRequest::query()->lockForUpdate()->findOrFail($requestId);
            $change($request);
            $this->audit->record($master, $session->campaign_id, $action, $request);
            $this->stream->append($session, 'roll.request_updated', RollViews::request($request, $this->subjectName($request)), ...$this->audiences($request));

            return $request;
        });
    }

    private static function violationCode(string $message): string
    {
        return match ($message) {
            'Roll request is not open.' => 'roll.request_not_open',
            'Roll request has expired.' => 'roll.request_expired',
            'Roll request revision mismatch.' => 'roll.revision_mismatch',
            'No remaining attempts.' => 'roll.no_attempts',
            'Chosen dice count outside authorized range.', 'Preset requires a fixed dice pool.' => 'roll.dice_count_not_allowed',
            default => 'roll.not_allowed',
        };
    }

    private static function invalid(string $code, string $pointer): ApiProblem
    {
        return ApiProblem::validation(new ValidationResult([new ValidationIssue($code, Severity::Error, [], [$pointer])]), $code);
    }
}
