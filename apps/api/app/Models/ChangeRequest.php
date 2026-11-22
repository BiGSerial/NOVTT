<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ChangeRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campaign_character_id', 'author_user_id', 'status', 'base_sheet_revision', 'draft_version', 'review_version', 'idempotency_key', 'payload_hash', 'resulting_sheet_revision', 'finalized_by', 'submitted_at', 'finalized_at'])]
class ChangeRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'change_requests';

    /** @var array<string, mixed> */
    protected $attributes = ['review_version' => 1, 'resulting_sheet_revision' => null, 'finalized_by' => null, 'finalized_at' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ChangeRequestStatus::class,
            'base_sheet_revision' => 'integer',
            'draft_version' => 'integer',
            'review_version' => 'integer',
            'resulting_sheet_revision' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CampaignCharacter, $this> */
    public function campaignCharacter(): BelongsTo
    {
        return $this->belongsTo(CampaignCharacter::class);
    }

    /** @return HasMany<ChangeOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ChangeOperation::class)->orderBy('position');
    }

    /** @return HasMany<OperationDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(OperationDecision::class);
    }
}
