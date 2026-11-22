<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campaign_id', 'aggregate_type', 'aggregate_id', 'event_type', 'schema_version', 'payload', 'occurred_at', 'published_at', 'attempts'])]
class OutboxEvent extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'outbox_events';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => JsonDocumentCast::class,
            'schema_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
