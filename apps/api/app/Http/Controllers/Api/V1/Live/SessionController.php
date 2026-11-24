<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\AuditTrail;
use App\Application\Idempotency;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Audience;
use App\Live\Clocks;
use App\Live\Plot;
use App\Live\SessionAccess;
use App\Live\SessionStream;
use App\Live\TableSnapshot;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignImage;
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

        return new JsonResponse(['session' => $session === null ? null : TableSnapshot::session($session, $role === CampaignRole::Master, $actor->getKey())]);
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
                $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
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
                    'plot' => Plot::fromRoteiro($roteiro),
                    'stage' => null,
                    'last_seq' => 0,
                    'revision' => 1,
                    'started_at' => now(),
                    // Session snapshot of the campaign's pinned preset version (ADR 0012).
                    'preset_version_id' => $campaign->preset_version_id,
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
            'compass.clocks.*.tone' => ['sometimes', 'string', 'in:'.implode(',', Clocks::TONES)],
            'compass.clocks.*.visibility' => ['sometimes', 'string', 'in:'.implode(',', Clocks::VISIBILITY)],
            'compass.clocks.*.player_ids' => ['sometimes', 'array', 'max:20'],
            'compass.clocks.*.player_ids.*' => ['uuid'],
            'compass.spotlight' => ['present', 'array', 'max:20'],
            'compass.spotlight.*' => ['integer', 'min:0', 'max:999'],
        ]);
        $players = $this->access->playerIds($session->campaign_id);
        $compass = [
            'scene' => $data['compass']['scene'], 'objective' => $data['compass']['objective'], 'next_clue' => $data['compass']['next_clue'],
            // Only active players of this campaign can be named on a clock.
            'clocks' => array_map(static fn (array $c): array => Clocks::normalize($c, $players), $data['compass']['clocks']),
            'spotlight' => (object) $data['compass']['spotlight'],
        ];

        $session = DB::transaction(function () use ($sessionId, $data, $compass, $players): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->revision !== $data['revision']) {
                throw ApiProblem::conflict('session.revision_mismatch', $session->revision);
            }
            $before = (array) (((array) ($session->compass ?? []))['clocks'] ?? []);
            $session->compass = $compass;
            $session->revision++;
            $this->stream->append($session, 'compass.updated', ['revision' => $session->revision, 'compass' => $compass], Audience::master());
            // Each player hears only about the clocks they may see, and only when that view changed.
            foreach ($players as $playerId) {
                $mine = Clocks::visibleTo($compass['clocks'], $playerId);
                if ($mine !== Clocks::visibleTo($before, $playerId)) {
                    $this->stream->append($session, 'clocks.updated', ['clocks' => $mine], Audience::user($playerId));
                }
            }

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

    /**
     * Walks the story (NT-03): the master edits the scene list and/or moves the current scene.
     * Moving the current scene re-points the compass at it (clocks and spotlight are kept).
     */
    public function plot(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:1'],
            'plot' => ['required', 'array'],
            'plot.current' => ['present', 'nullable', 'integer', 'min:0', 'max:'.(Plot::MAX_SCENES - 1)],
            'plot.scenes' => ['present', 'array', 'max:'.Plot::MAX_SCENES],
            'plot.scenes.*.id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'plot.scenes.*.title' => ['required', 'string', 'min:1', 'max:120'],
            'plot.scenes.*.goal' => ['sometimes', 'nullable', 'string', 'max:300'],
            'plot.scenes.*.status' => ['sometimes', 'in:pending,current,done'],
            ...Plot::sceneRules('plot.scenes.*'),
        ]);
        $scenes = $data['plot']['scenes'];
        $this->requireImages($session->campaign_id, array_column($scenes, 'image_id'), '/plot/scenes');
        $current = $data['plot']['current'];
        if ($current !== null && $current >= count($scenes)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('plot.current_invalid', Severity::Error, [], ['/plot/current'])]), 'plot.current_invalid');
        }
        $plot = Plot::normalize($scenes, $current);

        $session = DB::transaction(function () use ($sessionId, $data, $plot): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->revision !== $data['revision']) {
                throw ApiProblem::conflict('session.revision_mismatch', $session->revision);
            }
            $before = $session->plot ?? ['scenes' => [], 'current' => null];
            $scene = $plot['current'] === null ? null : $plot['scenes'][$plot['current']];
            $previous = ($before['current'] ?? null) === null ? null : ($before['scenes'][$before['current']]['id'] ?? null);
            $compass = (array) ($session->compass ?? []);
            if ($scene !== null && ($plot['current'] !== ($before['current'] ?? null) || $scene['id'] !== $previous)) {
                $next = Clue::query()->where(['session_id' => $session->getKey(), 'scene' => $scene['title']])->whereNull('revealed_at')->orderBy('created_at')->value('title');
                $compass = [
                    'scene' => $scene['title'], 'objective' => $scene['goal'], 'next_clue' => (string) ($next ?? ''),
                    'clocks' => $compass['clocks'] ?? [], 'spotlight' => (object) ($compass['spotlight'] ?? []),
                ];
                $session->compass = $compass;
            }
            $session->plot = $plot;
            $session->revision++;
            $this->stream->append($session, 'plot.updated', ['revision' => $session->revision, 'plot' => $plot, 'compass' => (object) $compass], Audience::master());

            return $session;
        });

        return new JsonResponse(TableSnapshot::session($session, true));
    }

    /** What the whole table sees now: a scene card with read-aloud text and art (NT-04). */
    public function stage(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:1'],
            'stage' => ['present', 'nullable', 'array'],
            'stage.title' => ['required_with:stage', 'string', 'min:1', 'max:120'],
            'stage.text' => ['sometimes', 'nullable', 'string', 'max:1500'],
            'stage.art' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Plot::ART)],
            'stage.image_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $stage = $data['stage'] === null ? null : [
            'title' => $data['stage']['title'],
            'text' => (string) ($data['stage']['text'] ?? ''),
            'art' => $data['stage']['art'] ?? null,
            'image_id' => $data['stage']['image_id'] ?? null,
            'shown_at' => now()->format(DATE_RFC3339_EXTENDED),
        ];
        $this->requireImages($session->campaign_id, [$stage['image_id'] ?? null], '/stage/image_id');

        $session = DB::transaction(function () use ($sessionId, $data, $stage): GameSession {
            $session = $this->access->lockOpen($sessionId);
            if ($session->revision !== $data['revision']) {
                throw ApiProblem::conflict('session.revision_mismatch', $session->revision);
            }
            $session->stage = $stage;
            $session->revision++;
            $this->stream->append($session, 'stage.updated', ['revision' => $session->revision, 'stage' => $stage], Audience::table());

            return $session;
        });

        return new JsonResponse(TableSnapshot::session($session, true));
    }

    /**
     * Table atmosphere (SPEC-MVP-003 NT-10): the master sets time of day, weather, intensity and
     * how fast game time flows; every member sees it. It has its own revision so frequent changes
     * (a slider) never conflict with plot, stage or compass edits guarded by the session revision.
     */
    public function atmosphere(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'hour' => ['required', 'numeric', 'min:0', 'lt:24', 'multiple_of:0.25'],
            'weather' => ['required', 'string', 'in:clear,clouds,rain,storm,fog,snow'],
            'intensity' => ['required', 'numeric', 'min:0', 'max:1'],
            // Fog is a layer of its own: it can sit over rain, snow or a storm (0 = none).
            'fog' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'time_scale' => ['required', 'integer', 'min:0', 'max:120'],
            'transition_seconds' => ['required', 'integer', 'min:0', 'max:30'],
        ]);

        $atmosphere = DB::transaction(function () use ($sessionId, $data): array {
            $session = $this->access->lockOpen($sessionId);
            $atmosphere = [
                'hour' => (float) $data['hour'],
                'weather' => (string) $data['weather'],
                'intensity' => round((float) $data['intensity'], 2),
                'fog' => round((float) ($data['fog'] ?? 0), 2),
                'time_scale' => (int) $data['time_scale'],
                'transition_seconds' => (int) $data['transition_seconds'],
                'set_at' => now()->format(DATE_RFC3339_EXTENDED),
                'revision' => (int) ($session->atmosphere['revision'] ?? 0) + 1,
            ];
            $session->atmosphere = $atmosphere; // saved by append() together with last_seq
            $this->stream->append($session, 'atmosphere.updated', $atmosphere, Audience::table());

            return $atmosphere;
        });

        return new JsonResponse($atmosphere);
    }

    /** @param  list<mixed>  $ids */
    private function requireImages(string $campaignId, array $ids, string $pointer): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v): bool => is_string($v) && $v !== '')));
        if ($ids !== [] && CampaignImage::query()->where('campaign_id', $campaignId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('image.unknown', Severity::Error, [], [$pointer])]), 'image.unknown');
        }
    }
}
