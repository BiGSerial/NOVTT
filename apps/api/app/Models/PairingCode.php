<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One-time device pairing code (digests only). */
#[Fillable(['code_hash', 'claim_hash', 'device_label', 'status', 'user_id', 'paired_device_id', 'claim_attempts', 'expires_at'])]
class PairingCode extends Model
{
    use HasUuids;

    protected $table = 'pairing_codes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'claim_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
