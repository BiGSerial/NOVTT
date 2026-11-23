<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['occurred_at', 'actor_user_id', 'campaign_id', 'action', 'subject_type', 'subject_id', 'metadata'])]
class AuditEvent extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'audit_events';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => JsonDocumentCast::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
