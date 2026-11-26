<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\OfficialRoll;
use App\Models\RollRequest;

/** Wire shapes of roll requests and official rolls (contracts/events/live-session-events.v1.schema.json). */
final class RollViews
{
    /** @return array<string, mixed> */
    public static function request(RollRequest $r, string $subjectName): array
    {
        return [
            'id' => $r->getKey(),
            'session_id' => $r->session_id,
            'subject' => self::subject($r, $subjectName),
            'roller_user_id' => $r->roller_user_id,
            'label' => $r->label,
            'components' => $r->components,
            'modifier' => $r->modifier,
            'dice' => self::dice($r),
            'min_dice' => $r->min_dice,
            'max_dice' => $r->max_dice,
            'allows_choice' => $r->allows_choice,
            'attempts_allowed' => $r->attempts_allowed,
            'attempts_consumed' => $r->attempts_consumed,
            'secret' => $r->secret,
            'status' => $r->status,
            'revision' => $r->revision,
            'expires_at' => $r->expires_at?->format(DATE_RFC3339_EXTENDED),
        ];
    }

    /**
     * @param  bool  $withNumbers  false for NPC rolls shown to players: faces and outcome, but no
     *                             NPC attribute values, modifier or bonus.
     * @return array<string, mixed>
     */
    public static function roll(OfficialRoll $roll, RollRequest $r, string $subjectName, bool $withNumbers = true): array
    {
        return [
            'id' => $roll->getKey(),
            'request_id' => $r->getKey(),
            'session_id' => $roll->session_id,
            'subject' => self::subject($r, $subjectName),
            'roller_user_id' => $r->roller_user_id,
            'label' => $r->label,
            'components' => $withNumbers ? $r->components : array_map(static fn (array $c): array => ['path' => $c['path'], 'label' => $c['label']], $r->components),
            'modifier' => $withNumbers ? $r->modifier : null,
            'dice' => [...self::dice($r), 'bonus' => $withNumbers ? $r->bonus : null],
            'results' => $roll->results,
            'total' => $roll->total,
            'successes' => $roll->successes,
            'succeeded' => $roll->succeeded,
            'attempt' => $roll->attempt,
            'attempts_allowed' => $r->attempts_allowed,
            'animation_seed' => $roll->animation_seed,
            'secret' => $roll->secret,
            'revealed' => $roll->revealed_at !== null,
            'created_at' => $roll->created_at->format(DATE_RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    private static function dice(RollRequest $r): array
    {
        return [
            'faces' => $r->faces,
            'count_mode' => $r->count_mode,
            'reading' => $r->reading,
            'threshold' => $r->threshold,
            'successes_per_die' => $r->successes_per_die,
            'target' => $r->target,
            'bonus' => $r->bonus,
        ];
    }

    /** @return array{kind: string, id: string, name: string} */
    private static function subject(RollRequest $r, string $name): array
    {
        return $r->campaign_character_id !== null
            ? ['kind' => 'character', 'id' => $r->campaign_character_id, 'name' => $name]
            : ['kind' => 'npc', 'id' => (string) $r->campaign_npc_id, 'name' => $name];
    }
}
