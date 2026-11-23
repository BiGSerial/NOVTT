<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\AuditTrail;
use App\Application\Idempotency;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Audience;
use App\Live\SessionAccess;
use App\Live\SessionStream;
use App\Live\TableSnapshot;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\Clue;
use App\Models\GameSession;
use App\Models\Roteiro;
use App\Models\SessionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/** Session lifecycle, snapshot, event recovery, compass and initiative (SPEC-MVP-002 LT-02/03/06). */
final class SessionController extends ApiController
{
    public function __construct(private readonly SessionAccess $access, private readonly SessionStream $stream, private readonly AuditTrail $audit) {}

    public function current(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->access->memberRole($actor, $campaignId);
        $session = GameSession::query()->where('campaign_id', $campaignId)->whereIn('status', ['active', 'paused'])->first();

        return new JsonResponse(['session' => $session === null ? null : TableSnapshot::session($session, $role === CampaignRole::Master)]);
    }

    public function start(Request $request, string $campaignId, Idempotency $idempotency): JsonResponse
    {
        $actor = $this->actor($request);
        $key = $this->idempotencyKey($request);
        $data = $this->input($request, [
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'roteiro_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $this->access->requireMaster($actor, $campaignId);

        try {
            [$status, $body] = DB::transaction(fn (): array => $idempotency->run($actor, 'session.start', $key, hash('sha256', $campaignId.CanonicalJson::encode($data)), function () use ($actor, $campaignId, $data): array {
                Campaign::query()->lockForUpdate()->findOrFail($campaignId);
                $roteiro = null;
                if (($data['roteiro_id'] ?? null) !== null) {
                    $roteiro = Roteiro::query()->where('campaign_id', $campaignId)->find($data['roteiro_id']) ?? throw ApiProblem::notFound();
                }
                $session = GameSession::query()->create([
                    'campaign_id' => $campaignId,
                    'title' => $data['title'],
                    'status' => 'active',
                    'started_by' => $actor->getKey(),
                    'roteiro_id' => $roteiro?->getKey(),
                    'compass' => $roteiro === null ? ['scene' => '', 'objective' => '', 'clocks' => [], 'next_clue' => '', 'spotlight' => (object) []] : $roteiro->compassSeed(),
                    'initiative' => ['entries' => [], 'turn' => 0, 'round' => 1],
                    'revealed_npcs' => [],
                    'last_seq' => 0,
                    'revision' => 1,
                    'started_at' => now(),
                ]);
                if ($roteiro !== null) {
                    foreach ($roteiro->clueSeeds() as $clue) {
                        Clue::query()->create([...$clue, 'campaign_id' => $campaignId, 'session_id' => $session->getKey()]);
                    }
                }
                $this->audit->record($actor, $campaignId, 'session.started', $session);
                $this->stream->append($session, 'session.status', ['status' => 'active', 'title' => $session->title], Audience::table());

                return [201, TableSnapshot::session($session, true)];
            }));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'game_sessions_one_open')) {
                throw ApiProblem::conflict('session.already_open');
            }
            throw $e;
        }

        return new JsonResponse($body, $status);
    }

    public function transition(Request $request, string $sessionId, string $action): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $target = ['pause' => 'paused', 'resume' => 'active', 'end' => 'ended'][$action];

        $session = DB::transaction(function () use ($actor, $sessionId, $target): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->status === $target) {
                return $session;
            }
            $session->status = $target;
            $session->revision++;
            if ($target === 'ended') {
                $session->ended_at = now();
                DB::table('roll_requests')->where('session_id', $sessionId)->where('status', 'OPEN')->update(['status' => 'CLOSED', 'updated_at' => now()]);
            }
            $this->audit->record($actor, $session->campaign_id, "session.{$target}", $session);
            $this->stream->append($session, 'session.status', ['status' => $target, 'title' => $session->title], Audience::table());

            return $session;
        });

        return new JsonResponse(TableSnapshot::session($session, true));
    }

    public function snapshot(Request $request, string $sessionId, TableSnapshot $snapshot): JsonResponse
    {
        $actor = $this->actor($request);
        [$session, $role] = $this->access->session($actor, $sessionId);

        return new JsonResponse($snapshot->build($session, $actor, $role === CampaignRole::Master));
    }

    /** Recovery (LT-03): events after a seq that this actor may see, in order. */
    public function events(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session, $role] = $this->access->session($actor, $sessionId);
        $after = max(0, (int) $request->query('after', 0));
        $limit = max(1, min(500, (int) $request->query('limit', 200)));
        $query = SessionEvent::query()->where('session_id', $session->getKey())->where('seq', '>', $after)
            ->where(function ($q) use ($actor, $role): void {
                $q->where('audience', 'table')->orWhere(fn ($u) => $u->where('audience', 'user')->where('audience_user_id', $actor->getKey()));
                if ($role === CampaignRole::Master) {
                    $q->orWhere('audience', 'master');
                }
            })
            ->orderBy('seq')->limit($limit + 1);
        $events = $query->get();
        $more = $events->count() > $limit;

        return new JsonResponse([
            'data' => $events->take($limit)->map(static fn (SessionEvent $e): array => SessionStream::envelope($e))->values(),
            'last_seq' => $session->last_seq,
            'has_more' => $more,
        ]);
    }

    public function compass(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:1'],
            'compass' => ['required', 'array'],
            'compass.scene' => ['present', 'string', 'max:120'],
            'compass.objective' => ['present', 'string', 'max:300'],
            'compass.next_clue' => ['present', 'string', 'max:300'],
            'compass.clocks' => ['present', 'array', 'max:8'],
            'compass.clocks.*.id' => ['required', 'string', 'max:40'],
            'compass.clocks.*.label' => ['required', 'string', 'max:80'],
            'compass.clocks.*.segments' => ['required', 'integer', 'min:2', 'max:12'],
            'compass.clocks.*.filled' => ['required', 'integer', 'min:0', 'max:12', 'lte:compass.clocks.*.segments'],
            'compass.spotlight' => ['present', 'array', 'max:20'],
            'compass.spotlight.*' => ['integer', 'min:0', 'max:999'],
        ]);
        $compass = [
            'scene' => $data['compass']['scene'], 'objective' => $data['compass']['objective'], 'next_clue' => $data['compass']['next_clue'],
            'clocks' => array_map(static fn (array $c): array => ['id' => $c['id'], 'label' => $c['label'], 'segments' => (int) $c['segments'], 'filled' => (int) $c['filled']], $data['compass']['clocks']),
            'spotlight' => (object) $data['compass']['spotlight'],
        ];

        $session = DB::transaction(function () use ($sessionId, $data, $compass): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->revision !== $data['revision']) {
                throw ApiProblem::conflict('session.revision_mismatch', $session->revision);
            }
            $session->compass = $compass;
            $session->revision++;
            $this->stream->append($session, 'compass.updated', ['revision' => $session->revision, 'compass' => $compass], Audience::master());

            return $session;
        });

        return new JsonResponse(TableSnapshot::session($session, true));
    }

    /** Initiative is table-visible: names and order only (never NPC numbers). */
    public function initiative(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:1'],
            'entries' => ['present', 'array', 'max:30'],
            'entries.*.kind' => ['required', 'in:character,npc'],
            'entries.*.id' => ['required', 'uuid'],
            'turn' => ['required', 'integer', 'min:0', 'max:29'],
            'round' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $campaignId = $session->campaign_id;
        $names = [
            'character' => CampaignCharacter::query()->where('campaign_id', $campaignId)->pluck('name', 'id')->all(),
            'npc' => CampaignNpc::query()->where('campaign_id', $campaignId)->pluck('name', 'id')->all(),
        ];
        $entries = [];
        foreach ($data['entries'] as $i => $entry) {
            $name = $names[$entry['kind']][$entry['id']] ?? null;
            if ($name === null) {
                throw ApiProblem::validation(new ValidationResult([new ValidationIssue('initiative.unknown_entry', Severity::Error, [], ["/entries/{$i}"])]), 'initiative.unknown_entry');
            }
            $entries[] = ['kind' => $entry['kind'], 'id' => $entry['id'], 'name' => $name];
        }
        $initiative = ['entries' => $entries, 'turn' => min($data['turn'], max(0, count($entries) - 1)), 'round' => $data['round']];

        $session = DB::transaction(function () use ($sessionId, $data, $initiative): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->revision !== $data['revision']) {
                throw ApiProblem::conflict('session.revision_mismatch', $session->revision);
            }
            $session->initiative = $initiative;
            $session->revision++;
            $this->stream->append($session, 'initiative.updated', ['revision' => $session->revision, 'initiative' => $initiative], Audience::table());

            return $session;
        });

        return new JsonResponse(TableSnapshot::session($session, true));
    }
}
