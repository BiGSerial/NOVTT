<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Committed server-side roll outcome (immutable except reveal). */
#[Fillable(['roll_request_id', 'session_id', 'actor_user_id', 'idempotency_key', 'payload_fingerprint', 'attempt', 'dice_count', 'results', 'total', 'successes', 'succeeded', 'animation_seed', 'secret', 'revealed_at', 'created_at'])]
class OfficialRoll extends Model
{
    use HasUuids;

    protected $table = 'official_rolls';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'results' => 'array',
            'attempt' => 'integer',
            'dice_count' => 'integer',
            'total' => 'integer',
            'successes' => 'integer',
            'succeeded' => 'boolean',
            'animation_seed' => 'integer',
            'secret' => 'boolean',
            'revealed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
