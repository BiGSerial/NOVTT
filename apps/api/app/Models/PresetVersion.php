<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocumentCast;
use App\Domain\PresetVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['definition_id', 'version', 'status', 'content_hash', 'manifest', 'sheet_schema', 'sheet_ui', 'rules', 'validation_report', 'engine_version', 'imported_by', 'published_at'])]
class PresetVersion extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'preset_versions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PresetVersionStatus::class,
            'manifest' => JsonDocumentCast::class,
            'sheet_schema' => JsonDocumentCast::class,
            'sheet_ui' => JsonDocumentCast::class,
            'rules' => JsonDocumentCast::class,
            'validation_report' => JsonDocumentCast::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PresetDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PresetDefinition::class, 'definition_id');
    }

    public function isCanonical(): bool
    {
        return $this->definition->owner_user_id === null;
    }
}
