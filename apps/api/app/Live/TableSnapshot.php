<?php

declare(strict_types=1);

namespace App\Live;

use App\Domain\CampaignCharacterStatus;
use App\Domain\MemberStatus;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\CampaignNpc;
use App\Models\Clue;
use App\Models\GameSession;
use App\Models\LiveState;
use App\Models\MasterNote;
use App\Models\OfficialRoll;
use App\Models\RollRequest;
use App\Models\RollSuggestion;
use App\Models\User;
use App\Models\Whisper;

/**
 * Everything one participant may see of a session, in one read (LT-03 reconciliation and first
 * load). Filtering happens here, per role; a player never receives master-only data.
 */
final class TableSnapshot
{
    public const RECENT_ROLLS = 60;

    /** @return array<string, mixed> */
    public static function session(GameSession $s, bool $asMaster, ?string $viewerId = null): array
    {
        $view = [
            'id' => $s->getKey(),
            'campaign_id' => $s->campaign_id,
            'title' => $s->title,
            'status' => $s->status,
            'roteiro_id' => $s->roteiro_id,
            'initiative' => (object) ($s->initiative ?? []),
            'revealed_npcs' => $s->revealed_npcs ?? [],
            'stage' => $s->stage,
            'atmosphere' => $s->atmosphere,
            'last_seq' => $s->last_seq,
            'revision' => $s->revision,
            'started_at' => $s->started_at->format(DATE_RFC3339_EXTENDED),
        ];
        if ($asMaster) {
            $view['compass'] = (object) ($s->compass ?? []);
            $view['plot'] = $s->plot ?? ['scenes' => [], 'current' => null];
        } elseif ($viewerId !== null) {
            $view['clocks'] = Clocks::visibleTo((array) (((array) ($s->compass ?? []))['clocks'] ?? []), $viewerId);
        }

        return $view;
    }

    /** @return array<string, mixed> */
    public function build(GameSession $session, User $actor, bool $asMaster): array
    {
        $campaignId = $session->campaign_id;
        $me = $actor->getKey();
        $members = CampaignMember::query()->join('users', 'users.id', '=', 'campaign_members.user_id')
            ->where('campaign_members.campaign_id', $campaignId)->where('campaign_members.status', MemberStatus::Active)
            ->orderBy('campaign_members.role')->orderBy('users.name')
            ->get(['campaign_members.user_id', 'campaign_members.role', 'users.name']);
        $characters = CampaignCharacter::query()->where('campaign_id', $campaignId)->where('status', '!=', CampaignCharacterStatus::Archived->value)->orderBy('name')
            ->get(['id', 'name', 'player_user_id', 'custodian_user_id', 'status', 'preset_version_id', 'portrait_image_id', 'sheet']);
        $npcs = $asMaster ? CampaignNpc::query()->where('campaign_id', $campaignId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'preset_version_id', 'portrait_image_id']) : collect();
        $states = LiveState::query()->where('campaign_id', $campaignId)
            ->when(! $asMaster, fn ($q) => $q->where('subject_kind', 'character'))
            ->get()->keyBy('subject_id');

        $requests = RollRequest::query()->where('session_id', $session->getKey())->where('status', 'OPEN')
            ->when(! $asMaster, fn ($q) => $q->where(fn ($v) => $v->where('secret', false)->orWhere('roller_user_id', $me)))
            ->orderBy('created_at')->get();
        $names = $characters->pluck('name', 'id')->all() + CampaignNpc::query()->where('campaign_id', $campaignId)->pluck('name', 'id')->all();

        $rolls = OfficialRoll::query()->join('roll_requests', 'roll_requests.id', '=', 'official_rolls.roll_request_id')
            ->where('official_rolls.session_id', $session->getKey())
            ->when(! $asMaster, fn ($q) => $q->where(fn ($v) => $v->where('official_rolls.secret', false)->orWhereNotNull('official_rolls.revealed_at')->orWhere('roll_requests.roller_user_id', $me)))
            ->orderByDesc('official_rolls.created_at')->limit(self::RECENT_ROLLS)
            ->get(['official_rolls.*']);
        $rollRequests = RollRequest::query()->whereIn('id', $rolls->pluck('roll_request_id')->unique()->all())->get()->keyBy('id');

        $whispers = Whisper::query()->where('campaign_id', $campaignId)
            ->where(fn ($q) => $q->where('sender_user_id', $me)->orWhere('recipient_user_id', $me)->when($asMaster, fn ($m) => $m->orWhereIn('sender_user_id', $this->masterIds($members))->orWhereIn('recipient_user_id', $this->masterIds($members))))
            ->orderByDesc('created_at')->limit(200)->get()->reverse()->values();

        $clues = Clue::query()->where('campaign_id', $campaignId)->where('session_id', $session->getKey())
            ->when(! $asMaster, fn ($q) => $q->where(fn ($v) => $v->where('revealed_to', 'table')->orWhere('revealed_user_id', $me)))
            ->orderBy('created_at')->get();

        $suggestions = RollSuggestion::query()->where('session_id', $session->getKey())->where('status', 'pending')
            ->when(! $asMaster, fn ($q) => $q->where('player_user_id', $me))->orderBy('created_at')->get();

        return [
            'contract' => 'novtt.table-snapshot/1',
            'role' => $asMaster ? 'master' : 'player',
            'me' => ['user_id' => $me, 'name' => $actor->name],
            'session' => self::session($session, $asMaster, $me),
            'members' => $members->map(static fn ($m): array => ['user_id' => $m->user_id, 'name' => $m->name, 'role' => is_string($m->role) ? $m->role : $m->role->value])->values(),
            'characters' => $characters->map(static fn (CampaignCharacter $c): array => [
                'id' => $c->getKey(), 'name' => $c->name, 'player_user_id' => $c->player_user_id,
                'controlled_by' => $c->custodian_user_id ?? $c->player_user_id, 'status' => $c->status->value,
                'preset_version_id' => $c->preset_version_id,
                'portrait_image_id' => $c->portrait_image_id,
                // Identity text is readable by its owner and the master only (sheet access rules).
                'concept' => $asMaster || ($c->custodian_user_id ?? $c->player_user_id) === $me ? self::concept($c) : null,
                'live' => self::live($states->get($c->getKey())),
            ])->values(),
            'npcs' => $npcs->map(static fn (CampaignNpc $n): array => ['id' => $n->getKey(), 'name' => $n->name, 'preset_version_id' => $n->preset_version_id, 'portrait_image_id' => $n->portrait_image_id, 'live' => self::live($states->get($n->getKey()))])->values(),
            'requests' => $requests->map(static fn (RollRequest $r): array => RollViews::request($r, (string) ($names[$r->campaign_character_id ?? $r->campaign_npc_id] ?? '')))->values(),
            'rolls' => $rolls->reverse()->map(function (OfficialRoll $roll) use ($rollRequests, $names, $asMaster): array {
                $r = $rollRequests[$roll->roll_request_id];

                return RollViews::roll($roll, $r, (string) ($names[$r->campaign_character_id ?? $r->campaign_npc_id] ?? ''), withNumbers: $asMaster || $r->campaign_npc_id === null);
            })->values(),
            'whispers' => $whispers->map(static fn (Whisper $w): array => LiveViews::whisper($w))->values(),
            'clues' => $clues->map(static fn (Clue $c): array => LiveViews::clue($c, $asMaster))->values(),
            'suggestions' => $suggestions->map(static fn (RollSuggestion $s): array => LiveViews::suggestion($s, (string) ($names[$s->campaign_character_id] ?? '')))->values(),
            'notes' => $asMaster ? MasterNote::query()->where('campaign_id', $campaignId)->whereNull('deleted_at')->orderByDesc('pinned')->orderByDesc('updated_at')->limit(200)->get()
                ->map(static fn (MasterNote $n): array => LiveViews::note($n))->values() : [],
        ];
    }

    /** @return array{resources: list<array<string, mixed>>, conditions: list<string>, items: list<array<string, mixed>>, revision: int}|null */
    public static function live(?LiveState $state): ?array
    {
        return $state === null ? null : ['resources' => $state->resources, 'conditions' => $state->conditions, 'items' => $state->items ?? [], 'revision' => $state->revision];
    }

    public static function concept(CampaignCharacter $c): ?string
    {
        $sheet = (array) $c->sheet;
        $identity = (array) ($sheet['identity'] ?? []);
        $value = $identity['concept'] ?? null;

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 200) : null;
    }

    /** @return list<string> */
    private function masterIds($members): array
    {
        return $members->filter(static fn ($m): bool => (is_string($m->role) ? $m->role : $m->role->value) === 'master')->pluck('user_id')->all();
    }
}
