<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Resources and conditions of a character or NPC during play (LT-05). */
#[Fillable(['campaign_id', 'subject_kind', 'subject_id', 'resources', 'conditions', 'items', 'revision'])]
class LiveState extends Model
{
    use HasUuids;

    protected $table = 'live_states';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resources' => 'array',
            'conditions' => 'array',
            'items' => 'array',
            'revision' => 'integer',
        ];
    }
}
