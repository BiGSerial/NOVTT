<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\CampaignStatus;
use App\Domain\MemberStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_user_id', 'preset_version_id', 'name', 'description', 'status', 'revision', 'archived_at'])]
class Campaign extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'campaigns';

    /** @var array<string, mixed> Mirrors database defaults so new instances are complete. */
    protected $attributes = ['status' => 'active', 'revision' => 1];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'revision' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PresetVersion, $this> */
    public function presetVersion(): BelongsTo
    {
        return $this->belongsTo(PresetVersion::class);
    }

    /** @return HasMany<CampaignMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class);
    }

    /** Active membership of a user, or null. */
    public function membershipOf(User $user): ?CampaignMember
    {
        return $this->members()->where('user_id', $user->getKey())->where('status', MemberStatus::Active)->first();
    }
}
