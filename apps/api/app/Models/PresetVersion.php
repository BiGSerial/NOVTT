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
use Novtt\Engine\Presets\PublicationGate;

#[Fillable(['definition_id', 'version', 'status', 'content_hash', 'manifest', 'sheet_schema', 'sheet_ui', 'rules', 'validation_report', 'engine_version', 'imported_by', 'published_at', 'rights_status', 'rights_updated_at', 'compendium'])]
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
            'rights_updated_at' => 'immutable_datetime',
            'compendium' => JsonDocumentCast::class,
        ];
    }

    /** @return BelongsTo<PresetDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PresetDefinition::class, 'definition_id');
    }

    /** The sheet JSON Schema as an object tree, exactly as imported (keeps {} vs []). */
    public function sheetSchemaObject(): object
    {
        return json_decode((string) $this->getRawOriginal('sheet_schema'), false, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function sheetSchemaArray(): array
    {
        return json_decode((string) $this->getRawOriginal('sheet_schema'), true, 64, JSON_THROW_ON_ERROR);
    }

    /** Whether the version may appear in (and be pinned from) the public catalogue. */
    public function isPubliclyListable(): bool
    {
        return PublicationGate::publiclyListable($this->status->value, $this->rights_status);
    }

    /** The rules revision declared by a manifest/2 (null for manifest/1). */
    public function rulesRevision(): ?string
    {
        $revision = $this->manifest['rules_revision'] ?? null;

        return is_string($revision) ? $revision : null;
    }

    public function isCanonical(): bool
    {
        return $this->definition->owner_user_id === null;
    }
}
