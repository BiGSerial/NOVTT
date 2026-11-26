<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A master's second device (tablet) with its own revocable session (ADR 0011). */
#[Fillable(['user_id', 'label', 'last_seen_at', 'revoked_at'])]
class PairedDevice extends Model
{
    use HasUuids;

    protected $table = 'paired_devices';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
