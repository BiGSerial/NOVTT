<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A combination a player proposes; only the master can turn it into a request. */
#[Fillable(['session_id', 'campaign_character_id', 'player_user_id', 'paths', 'note', 'status', 'roll_request_id'])]
class RollSuggestion extends Model
{
    use HasUuids;

    protected $table = 'roll_suggestions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paths' => 'array',
        ];
    }
}
