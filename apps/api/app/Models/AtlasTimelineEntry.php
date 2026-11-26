<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** How an Atlas entry evolved, optionally tied to a session. */
#[Fillable(['entry_id', 'session_id', 'note', 'created_by', 'created_at'])]
class AtlasTimelineEntry extends Model
{
    use HasUuids;

    protected $table = 'atlas_timeline';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
