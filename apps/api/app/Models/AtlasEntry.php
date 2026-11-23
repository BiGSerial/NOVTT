<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A place, person, faction, secret or story thread of the campaign world (LT-07). */
#[Fillable(['campaign_id', 'kind', 'title', 'summary', 'details', 'known_to_players', 'thread_status', 'links', 'revision', 'archived_at'])]
class AtlasEntry extends Model
{
    use HasUuids;

    protected $table = 'atlas_entries';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'known_to_players' => 'boolean',
            'links' => 'array',
            'revision' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
