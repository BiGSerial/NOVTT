<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\Clue;
use App\Models\MasterNote;
use App\Models\RollSuggestion;
use App\Models\Whisper;

/** Wire shapes of the live table records. */
final class LiveViews
{
    /** @return array<string, mixed> */
    public static function whisper(Whisper $w): array
    {
        return [
            'id' => $w->getKey(),
            'sender_user_id' => $w->sender_user_id,
            'recipient_user_id' => $w->recipient_user_id,
            'kind' => $w->kind,
            'body' => $w->body,
            'created_at' => $w->created_at->format(DATE_RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    public static function clue(Clue $c, bool $asMaster): array
    {
        return [
            'id' => $c->getKey(),
            'scene' => $c->scene,
            'title' => $c->title,
            'body' => $c->body,
            'revealed_to' => $c->revealed_to,
            'revealed_user_id' => $asMaster ? $c->revealed_user_id : null,
            'revealed_at' => $c->revealed_at?->format(DATE_RFC3339_EXTENDED),
        ];
    }

    /** @return array<string, mixed> */
    public static function suggestion(RollSuggestion $s, string $characterName): array
    {
        return [
            'id' => $s->getKey(),
            'campaign_character_id' => $s->campaign_character_id,
            'character_name' => $characterName,
            'player_user_id' => $s->player_user_id,
            'paths' => $s->paths,
            'note' => $s->note,
            'status' => $s->status,
        ];
    }

    /** @return array<string, mixed> */
    public static function note(MasterNote $n): array
    {
        return [
            'id' => $n->getKey(),
            'session_id' => $n->session_id,
            'body' => $n->body,
            'tags' => $n->tags,
            'pinned' => $n->pinned,
            'revision' => $n->revision,
            'updated_at' => $n->updated_at?->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
