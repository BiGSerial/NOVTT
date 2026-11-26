<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Private message between the master and exactly one player. */
#[Fillable(['campaign_id', 'session_id', 'sender_user_id', 'recipient_user_id', 'kind', 'body', 'created_at'])]
class Whisper extends Model
{
    use HasUuids;

    protected $table = 'whispers';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
