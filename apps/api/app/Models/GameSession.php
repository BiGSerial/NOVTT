<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Live session of a campaign (SPEC-MVP-002 LT-02). */
#[Fillable(['campaign_id', 'title', 'status', 'started_by', 'roteiro_id', 'compass', 'initiative', 'revealed_npcs', 'plot', 'stage', 'last_seq', 'revision', 'started_at', 'ended_at', 'preset_version_id'])]
class GameSession extends Model
{
    use HasUuids;

    protected $table = 'game_sessions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'compass' => JsonDocumentCast::class,
            'initiative' => JsonDocumentCast::class,
            'revealed_npcs' => 'array',
            'plot' => JsonDocumentCast::class,
            'stage' => 'array',
            'last_seq' => 'integer',
            'revision' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
