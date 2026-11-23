<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Private master note (SPEC-002 MW-08). */
#[Fillable(['campaign_id', 'session_id', 'author_user_id', 'body', 'tags', 'pinned', 'revision', 'deleted_at'])]
class MasterNote extends Model
{
    use HasUuids;

    protected $table = 'master_notes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'pinned' => 'boolean',
            'revision' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
