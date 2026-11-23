<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Master-authorized test (ADR 0004, SPEC-003, LT-04). */
#[Fillable(['campaign_id', 'session_id', 'campaign_character_id', 'campaign_npc_id', 'roller_user_id', 'requested_by', 'label', 'components', 'modifier', 'faces', 'count_mode', 'reading', 'threshold', 'successes_per_die', 'bonus', 'target', 'min_dice', 'max_dice', 'allows_choice', 'attempts_allowed', 'attempts_consumed', 'secret', 'status', 'revision', 'expires_at'])]
class RollRequest extends Model
{
    use HasUuids;

    protected $table = 'roll_requests';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'components' => 'array',
            'modifier' => 'integer',
            'faces' => 'integer',
            'threshold' => 'integer',
            'successes_per_die' => 'integer',
            'bonus' => 'integer',
            'target' => 'integer',
            'min_dice' => 'integer',
            'max_dice' => 'integer',
            'allows_choice' => 'boolean',
            'attempts_allowed' => 'integer',
            'attempts_consumed' => 'integer',
            'secret' => 'boolean',
            'revision' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
