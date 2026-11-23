<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Prepared clue; revealed to the table or whispered to one player. */
#[Fillable(['campaign_id', 'session_id', 'scene', 'title', 'body', 'revealed_to', 'revealed_user_id', 'revealed_at'])]
class Clue extends Model
{
    use HasUuids;

    protected $table = 'clues';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revealed_at' => 'immutable_datetime',
        ];
    }
}
