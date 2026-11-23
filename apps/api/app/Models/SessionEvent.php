<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only session event; also the realtime outbox (LT-03). */
#[Fillable(['session_id', 'campaign_id', 'seq', 'audience', 'audience_user_id', 'type', 'payload', 'occurred_at', 'published_at', 'attempts'])]
class SessionEvent extends Model
{
    use HasUuids;

    protected $table = 'session_events';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => JsonDocumentCast::class,
            'seq' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
