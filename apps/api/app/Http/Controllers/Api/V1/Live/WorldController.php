<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Domain\CampaignCharacterStatus;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Plot;
use App\Live\SessionAccess;
use App\Models\AtlasEntry;
use App\Models\AtlasTimelineEntry;
use App\Models\CampaignCharacter;
use App\Models\CampaignImage;
use App\Models\Clue;
use App\Models\GameSession;
use App\Models\Roteiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Session prep (Roteiro) and the campaign world (Atlas), SPEC-MVP-002 LT-07. Master-only, except
 * that players can read the public summary of Atlas entries marked known to players.
 */
final class WorldController extends ApiController
{
    public function __construct(private readonly SessionAccess $access) {}

    // ---- Roteiro ----

    public function roteiros(Request $request, string $campaignId): JsonResponse
    {
        $this->access->requireMaster($this->actor($request), $campaignId);

        return new JsonResponse(['data' => Roteiro::query()->where('campaign_id', $campaignId)->whereNull('archived_at')->orderByDesc('updated_at')->get()
            ->map(static fn (Roteiro $r): array => self::roteiro($r))->values()]);
    }

    public function createRoteiro(Request $request, string $campaignId): JsonResponse
    {
        $this->access->requireMaster($this->actor($request), $campaignId);
        $data = $this->input($request, ['title' => ['required', 'string', 'min:1', 'max:120'], ...self::contentRules()]);
        $this->requireSceneImages($campaignId, $data);
        $roteiro = Roteiro::query()->create(['campaign_id' => $campaignId, 'title' => $data['title'], 'content' => self::content($data['content'] ?? []), 'revision' => 1]);

        return new JsonResponse(self::roteiro($roteiro), 201);
    }

    public function updateRoteiro(Request $request, string $roteiroId): JsonResponse
    {
        $roteiro = $this->masterOwned($request, Roteiro::class, $roteiroId);
        $data = $this->input($request, ['revision' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'min:1', 'max:120'], ...self::contentRules()]);
        $this->requireSceneImages($roteiro->campaign_id, $data);
        $roteiro = DB::transaction(function () use ($roteiro, $data): Roteiro {
            $locked = Roteiro::query()->lockForUpdate()->findOrFail($roteiro->getKey());
            if ($locked->revision !== $data['revision']) {
                throw ApiProblem::conflict('roteiro.revision_mismatch', $locked->revision);
            }
            $locked->update(['title' => $data['title'], 'content' => self::content($data['content'] ?? []), 'revision' => $locked->revision + 1]);

            return $locked;
        });

        return new JsonResponse(self::roteiro($roteiro));
    }

    public function archiveRoteiro(Request $request, string $roteiroId): JsonResponse
    {
        $this->masterOwned($request, Roteiro::class, $roteiroId)->update(['archived_at' => now()]);

        return new JsonResponse(null, 204);
    }

    // ---- Atlas ----

    public function atlas(Request $request, string $campaignId): JsonResponse
    {
        $role = $this->access->memberRole($this->actor($request), $campaignId);
        $master = $role === CampaignRole::Master;
        $entries = AtlasEntry::query()->where('campaign_id', $campaignId)->whereNull('archived_at')
            ->when(! $master, fn ($q) => $q->where('known_to_players', true)->where('kind', '!=', 'secret'))
            ->orderBy('kind')->orderBy('title')->get();
        $timeline = $master ? AtlasTimelineEntry::query()->whereIn('entry_id', $entries->pluck('id')->all())->orderBy('created_at')->get()->groupBy('entry_id') : collect();

        return new JsonResponse(['data' => $entries->map(static fn (AtlasEntry $e): array => self::entry($e, $master, $timeline->get($e->getKey(), collect())->all()))->values()]);
    }

    public function createEntry(Request $request, string $campaignId): JsonResponse
    {
        $this->access->requireMaster($this->actor($request), $campaignId);
        $data = $this->input($request, self::entryRules(false));
        $links = $this->validLinks($campaignId, $data['links'] ?? []);
        $entry = AtlasEntry::query()->create([
            'campaign_id' => $campaignId, 'kind' => $data['kind'], 'title' => $data['title'], 'summary' => $data['summary'] ?? '', 'details' => $data['details'] ?? '',
            'known_to_players' => $data['kind'] !== 'secret' && ($data['known_to_players'] ?? false),
            'thread_status' => $data['kind'] === 'thread' ? ($data['thread_status'] ?? 'open') : null, 'links' => $links, 'revision' => 1,
        ]);

        return new JsonResponse(self::entry($entry, true, []), 201);
    }

    public function updateEntry(Request $request, string $entryId): JsonResponse
    {
        $entry = $this->masterOwned($request, AtlasEntry::class, $entryId);
        $data = $this->input($request, self::entryRules(true));
        $links = array_key_exists('links', $data) ? $this->validLinks($entry->campaign_id, $data['links'], $entry->getKey()) : null;
        $entry = DB::transaction(function () use ($entry, $data, $links): AtlasEntry {
            $locked = AtlasEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            if ($locked->revision !== $data['revision']) {
                throw ApiProblem::conflict('atlas.revision_mismatch', $locked->revision);
            }
            $kind = $data['kind'] ?? $locked->kind;
            $locked->update([
                'kind' => $kind,
                'title' => $data['title'] ?? $locked->title,
                'summary' => $data['summary'] ?? $locked->summary,
                'details' => $data['details'] ?? $locked->details,
                'known_to_players' => $kind !== 'secret' && ($data['known_to_players'] ?? $locked->known_to_players),
                'thread_status' => $kind === 'thread' ? ($data['thread_status'] ?? $locked->thread_status ?? 'open') : null,
                'links' => $links ?? $locked->links,
                'revision' => $locked->revision + 1,
            ]);

            return $locked;
        });
        $timeline = AtlasTimelineEntry::query()->where('entry_id', $entry->getKey())->orderBy('created_at')->get()->all();

        return new JsonResponse(self::entry($entry, true, $timeline));
    }

    public function addTimeline(Request $request, string $entryId): JsonResponse
    {
        $entry = $this->masterOwned($request, AtlasEntry::class, $entryId);
        $data = $this->input($request, ['note' => ['required', 'string', 'min:1', 'max:500'], 'session_id' => ['sometimes', 'nullable', 'uuid']]);
        $sessionId = $data['session_id'] ?? GameSession::query()->where('campaign_id', $entry->campaign_id)->whereIn('status', ['active', 'paused'])->value('id');
        if ($sessionId !== null && GameSession::query()->where('campaign_id', $entry->campaign_id)->whereKey($sessionId)->doesntExist()) {
            throw ApiProblem::notFound();
        }
        $item = AtlasTimelineEntry::query()->create(['entry_id' => $entry->getKey(), 'session_id' => $sessionId, 'note' => $data['note'], 'created_by' => $this->actor($request)->getKey(), 'created_at' => now()]);

        return new JsonResponse(self::timelineItem($item), 201);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    private function masterOwned(Request $request, string $model, string $id): mixed
    {
        $record = Str::isUuid($id) ? $model::query()->whereNull('archived_at')->find($id) : null;
        if ($record === null || $this->access->role($this->actor($request), $record->campaign_id) !== CampaignRole::Master) {
            throw ApiProblem::notFound();
        }

        return $record;
    }

    /**
     * @param  list<string>  $links
     * @return list<string>
     */
    private function validLinks(string $campaignId, array $links, ?string $self = null): array
    {
        $links = array_values(array_unique(array_filter($links, static fn ($l): bool => $l !== $self)));
        $found = AtlasEntry::query()->where('campaign_id', $campaignId)->whereIn('id', $links)->count();
        if ($found !== count($links)) {
            throw new ApiProblem(422, 'atlas.link_invalid');
        }

        return $links;
    }

    /** @param  array<string, mixed>  $data */
    private function requireSceneImages(string $campaignId, array $data): void
    {
        $ids = array_values(array_unique(array_filter(array_column($data['content']['scenes'] ?? [], 'image_id'), static fn ($v): bool => is_string($v))));
        if ($ids !== [] && CampaignImage::query()->where('campaign_id', $campaignId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new ApiProblem(422, 'image.unknown');
        }
    }

    /**
     * Prep carry-over (NT-05): what the next roteiro should start from, so the master never
     * prepares from a blank page: unrevealed clues, open threads and what each character wants.
     */
    public function carryOver(Request $request, string $campaignId): JsonResponse
    {
        $this->access->requireMaster($this->actor($request), $campaignId);
        $last = GameSession::query()->where('campaign_id', $campaignId)->orderByDesc('started_at')->first();
        $clues = $last === null ? collect() : Clue::query()->where('session_id', $last->getKey())->whereNull('revealed_at')->orderBy('created_at')->limit(30)->get(['title', 'body', 'scene']);
        $threads = AtlasEntry::query()->where(['campaign_id' => $campaignId, 'kind' => 'thread'])->where(fn ($q) => $q->whereNull('thread_status')->orWhere('thread_status', '!=', 'resolved'))
            ->orderBy('title')->limit(30)->get(['id', 'title', 'summary', 'thread_status']);
        $characters = CampaignCharacter::query()->where('campaign_id', $campaignId)->where('status', '!=', CampaignCharacterStatus::Archived->value)->orderBy('name')->get(['id', 'name', 'sheet']);
        $roteiro = Roteiro::query()->where('campaign_id', $campaignId)->whereNull('archived_at')->orderByDesc('updated_at')->value('title');

        return new JsonResponse([
            'unrevealed_clues' => $clues->map(static fn (Clue $c): array => ['title' => $c->title, 'body' => $c->body, 'scene' => $c->scene])->values(),
            'open_threads' => $threads->map(static fn (AtlasEntry $e): array => ['id' => $e->getKey(), 'title' => $e->title, 'summary' => $e->summary, 'thread_status' => $e->thread_status])->values(),
            'characters' => $characters->map(static function (CampaignCharacter $c): array {
                $identity = (array) (((array) $c->sheet)['identity'] ?? []);
                $text = static fn ($v): ?string => is_string($v) && $v !== '' ? mb_substr($v, 0, 300) : null;

                return ['id' => $c->getKey(), 'name' => $c->name, 'concept' => $text($identity['concept'] ?? null), 'motivation' => $text($identity['motivation'] ?? null)];
            })->values(),
            'last_roteiro_title' => $roteiro,
        ]);
    }

    /** @return array<string, mixed> */
    private static function roteiro(Roteiro $r): array
    {
        return ['id' => $r->getKey(), 'campaign_id' => $r->campaign_id, 'title' => $r->title, 'content' => (object) ($r->content ?? []), 'revision' => $r->revision, 'updated_at' => $r->updated_at?->format(DATE_RFC3339_EXTENDED)];
    }

    /**
     * @param  list<AtlasTimelineEntry>  $timeline
     * @return array<string, mixed>
     */
    private static function entry(AtlasEntry $e, bool $master, array $timeline): array
    {
        $view = ['id' => $e->getKey(), 'kind' => $e->kind, 'title' => $e->title, 'summary' => $e->summary, 'thread_status' => $e->thread_status];
        if ($master) {
            $view += ['details' => $e->details, 'known_to_players' => $e->known_to_players, 'links' => $e->links, 'revision' => $e->revision, 'timeline' => array_map(self::timelineItem(...), $timeline)];
        }

        return $view;
    }

    /** @return array<string, mixed> */
    private static function timelineItem(AtlasTimelineEntry $t): array
    {
        return ['id' => $t->getKey(), 'session_id' => $t->session_id, 'note' => $t->note, 'created_at' => $t->created_at->format(DATE_RFC3339_EXTENDED)];
    }

    /** @return array<string, mixed> */
    private static function contentRules(): array
    {
        return [
            'content' => ['sometimes', 'array'],
            'content.strong_start' => ['sometimes', 'string', 'max:1000'],
            'content.scenes' => ['sometimes', 'array', 'max:'.Plot::MAX_SCENES],
            ...Plot::sceneRules('content.scenes.*'),
            'content.scenes.*.title' => ['required', 'string', 'max:120'],
            'content.scenes.*.goal' => ['present', 'string', 'max:300'],
            'content.scenes.*.clues' => ['present', 'array', 'max:8'],
            'content.scenes.*.clues.*.title' => ['required', 'string', 'max:120'],
            'content.scenes.*.clues.*.body' => ['present', 'string', 'max:1000'],
            'content.npcs' => ['sometimes', 'array', 'max:20'],
            'content.npcs.*.name' => ['required', 'string', 'max:80'],
            'content.npcs.*.voice' => ['present', 'string', 'max:200'],
            'content.npcs.*.wants' => ['present', 'string', 'max:200'],
            'content.npcs.*.secret' => ['present', 'string', 'max:300'],
            'content.clocks' => ['sometimes', 'array', 'max:6'],
            'content.clocks.*.label' => ['required', 'string', 'max:80'],
            'content.clocks.*.segments' => ['required', 'integer', 'min:2', 'max:12'],
            'content.wants' => ['sometimes', 'array', 'max:12'],
            'content.wants.*.character' => ['required', 'string', 'max:80'],
            'content.wants.*.text' => ['present', 'string', 'max:300'],
            'content.locations' => ['sometimes', 'array', 'max:8'],
            'content.locations.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'content.locations.*.aspects' => ['present', 'array', 'max:3'],
            'content.locations.*.aspects.*' => ['string', 'max:120'],
            'content.rewards' => ['sometimes', 'array', 'max:10'],
            'content.rewards.*' => ['string', 'max:200'],
        ];
    }

    /** Keeps only the declared shape (validation already bounded every field). */
    private static function content(array $c): array
    {
        $str = static fn ($v): string => is_string($v) ? $v : '';

        return [
            'strong_start' => $str($c['strong_start'] ?? ''),
            'scenes' => array_map(static fn (array $s): array => [
                'title' => $str($s['title'] ?? ''), 'goal' => $str($s['goal'] ?? ''),
                'location' => $str($s['location'] ?? ''), 'read_aloud' => $str($s['read_aloud'] ?? ''),
                'npcs' => array_values(array_map($str, $s['npcs'] ?? [])),
                'art' => in_array($s['art'] ?? null, Plot::ART, true) ? $s['art'] : null,
                'image_id' => is_string($s['image_id'] ?? null) ? $s['image_id'] : null,
                'complication' => $str($s['complication'] ?? ''),
                'clues' => array_map(static fn (array $k): array => ['title' => $str($k['title'] ?? ''), 'body' => $str($k['body'] ?? '')], $s['clues'] ?? []),
            ], $c['scenes'] ?? []),
            'npcs' => array_map(static fn (array $n): array => ['name' => $str($n['name'] ?? ''), 'voice' => $str($n['voice'] ?? ''), 'wants' => $str($n['wants'] ?? ''), 'secret' => $str($n['secret'] ?? '')], $c['npcs'] ?? []),
            'clocks' => array_map(static fn (array $k): array => ['label' => $str($k['label'] ?? ''), 'segments' => (int) ($k['segments'] ?? 4)], $c['clocks'] ?? []),
            'wants' => array_map(static fn (array $w): array => ['character' => $str($w['character'] ?? ''), 'text' => $str($w['text'] ?? '')], $c['wants'] ?? []),
            'locations' => array_map(static fn (array $l): array => ['name' => $str($l['name'] ?? ''), 'aspects' => array_values(array_map($str, $l['aspects'] ?? []))], $c['locations'] ?? []),
            'rewards' => array_values(array_map($str, $c['rewards'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private static function entryRules(bool $update): array
    {
        $req = $update ? 'sometimes' : 'required';

        return [
            ...($update ? ['revision' => ['required', 'integer', 'min:1']] : []),
            'kind' => [$req, 'in:place,person,faction,secret,thread'],
            'title' => [$req, 'string', 'min:1', 'max:120'],
            'summary' => ['sometimes', 'string', 'max:500'],
            'details' => ['sometimes', 'string', 'max:8000'],
            'known_to_players' => ['sometimes', 'boolean'],
            'thread_status' => ['sometimes', 'nullable', 'in:open,advancing,resolved'],
            'links' => ['sometimes', 'array', 'max:20'],
            'links.*' => ['uuid'],
        ];
    }
}
