<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\CampaignCharacterStatus;
use App\Domain\MemberStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\PresetVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in person's own space: preferences synced across devices (dice sets with textures),
 * their characters in every campaign, and the people they play with (with coarse presence).
 */
final class MeController extends ApiController
{
    public const ONLINE_SECONDS = 120;

    public const TEXTURES = ['none', 'marble', 'granite', 'nebula', 'wood', 'scales', 'speckle', 'veins'];

    public const FINISHES = ['matte', 'satin', 'gloss', 'metal'];

    public function preferences(Request $request): JsonResponse
    {
        return new JsonResponse(self::preferencesOf($this->actor($request)->getKey()));
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $hex = ['required', 'string', 'regex:/^#[0-9a-f]{6}$/'];
        $data = $this->input($request, [
            'dice_sets' => ['present', 'array', 'max:12'],
            'dice_sets.*.id' => ['required', 'string', 'regex:/^[a-z0-9-]{1,32}$/', 'distinct'],
            'dice_sets.*.name' => ['required', 'string', 'min:1', 'max:40'],
            'dice_sets.*.body' => $hex,
            'dice_sets.*.ink' => $hex,
            'dice_sets.*.finish' => ['required', 'in:' . implode(',', self::FINISHES)],
            'dice_sets.*.texture' => ['sometimes', 'array'],
            'dice_sets.*.texture.pattern' => ['required_with:dice_sets.*.texture', 'in:' . implode(',', self::TEXTURES)],
            'dice_sets.*.texture.accent' => ['required_with:dice_sets.*.texture', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'dice_sets.*.texture.scale' => ['sometimes', 'numeric', 'min:0.5', 'max:3'],
            'active_dice_set' => ['present', 'nullable', 'string', 'max:32'],
            'dice_sound' => ['sometimes', 'boolean'],
        ]);
        $sets = array_map(static fn (array $s): array => [
            'id' => $s['id'], 'name' => $s['name'], 'body' => $s['body'], 'ink' => $s['ink'], 'finish' => $s['finish'],
            ...(isset($s['texture']) ? ['texture' => ['pattern' => $s['texture']['pattern'], 'accent' => $s['texture']['accent'], 'scale' => round((float) ($s['texture']['scale'] ?? 1), 2)]] : []),
        ], $data['dice_sets']);
        $ids = array_column($sets, 'id');
        $preferences = [
            'dice_sets' => $sets,
            'active_dice_set' => in_array($data['active_dice_set'], $ids, true) ? $data['active_dice_set'] : null,
            'dice_sound' => (bool) ($data['dice_sound'] ?? self::preferencesOf($actor->getKey())['dice_sound']),
        ];
        DB::table('users')->where('id', $actor->getKey())->update(['preferences' => json_encode($preferences, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

        return new JsonResponse($preferences);
    }

    /** @return array<string, mixed> */
    public static function preferencesOf(string $userId): array
    {
        // Read from the row, not a possibly stale model instance.
        $raw = DB::table('users')->where('id', $userId)->value('preferences');
        $p = is_string($raw) ? (array) json_decode($raw, true) : [];

        return ['dice_sets' => array_values((array) ($p['dice_sets'] ?? [])), 'active_dice_set' => $p['active_dice_set'] ?? null, 'dice_sound' => (bool) ($p['dice_sound'] ?? true)];
    }

    /** Every character the person plays (or holds), in every campaign, with its sheet version. */
    public function characters(Request $request): JsonResponse
    {
        $me = $this->actor($request)->getKey();
        $characters = CampaignCharacter::query()
            ->where(fn ($q) => $q->where('player_user_id', $me)->orWhere('custodian_user_id', $me))
            ->whereIn('campaign_id', CampaignMember::query()->select('campaign_id')->where('user_id', $me)->where('status', MemberStatus::Active))
            ->where('status', '!=', CampaignCharacterStatus::Archived->value)
            ->orderBy('name')->limit(200)->get();
        $campaigns = Campaign::query()->whereIn('id', $characters->pluck('campaign_id')->unique())->get(['id', 'name', 'preset_version_id'])->keyBy('id');
        $versions = PresetVersion::query()->with('definition')
            ->whereIn('id', $characters->pluck('preset_version_id')->merge($campaigns->pluck('preset_version_id'))->unique())->get()->keyBy('id');
        $label = static fn (?PresetVersion $v): ?array => $v === null ? null : ['id' => $v->getKey(), 'version' => $v->version, 'name' => (string) (((array) $v->manifest)['display_name'] ?? ''), 'definition_id' => $v->definition_id];

        return new JsonResponse(['data' => $characters->map(static function (CampaignCharacter $c) use ($campaigns, $versions, $label): array {
            $campaign = $campaigns[$c->campaign_id];

            return [
                'id' => $c->getKey(), 'name' => $c->name, 'status' => $c->status->value, 'portrait_image_id' => $c->portrait_image_id,
                'campaign' => ['id' => $campaign->getKey(), 'name' => $campaign->name],
                'sheet' => $label($versions[$c->preset_version_id] ?? null),
                'campaign_sheet' => $label($versions[$campaign->preset_version_id] ?? null),
                'outdated' => $c->preset_version_id !== $campaign->preset_version_id,
            ];
        })->values()]);
    }

    /** People who share at least one active campaign with the actor, with coarse presence. */
    public function companions(Request $request): JsonResponse
    {
        $me = $this->actor($request)->getKey();
        $mine = CampaignMember::query()->where('user_id', $me)->where('status', MemberStatus::Active)->pluck('campaign_id');
        $rows = CampaignMember::query()->join('users', 'users.id', '=', 'campaign_members.user_id')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_members.campaign_id')
            ->whereIn('campaign_members.campaign_id', $mine)->where('campaign_members.status', MemberStatus::Active)->where('campaign_members.user_id', '!=', $me)
            ->orderBy('users.name')
            ->get(['users.id as user_id', 'users.name', 'users.last_seen_at', 'campaigns.id as campaign_id', 'campaigns.name as campaign_name', 'campaign_members.role']);
        $people = [];
        $threshold = time() - self::ONLINE_SECONDS;
        foreach ($rows as $r) {
            $seen = $r->last_seen_at === null ? null : strtotime((string) $r->last_seen_at);
            $people[$r->user_id] ??= [
                'user_id' => $r->user_id, 'name' => $r->name,
                'online' => $seen !== null && $seen >= $threshold,
                // Day-level only when offline: enough for "seen today/yesterday", not a tracker.
                'last_seen_on' => $seen === null ? null : gmdate('Y-m-d', $seen),
                'campaigns' => [],
            ];
            $people[$r->user_id]['campaigns'][] = ['id' => $r->campaign_id, 'name' => $r->campaign_name, 'role' => is_string($r->role) ? $r->role : $r->role->value];
        }
        $list = array_values($people);
        usort($list, static fn (array $a, array $b): int => [$b['online'], $a['name']] <=> [$a['online'], $b['name']]);

        return new JsonResponse(['data' => $list]);
    }
}
