<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PresetVersion;

final class PresetVersionPresenter
{
    /** @return array<string, mixed> */
    public static function summary(PresetVersion $version): array
    {
        $definition = $version->definition;

        return [
            'id' => $version->getKey(),
            'family_id' => $version->manifest['family_id'],
            'variant_id' => $definition->variant_slug,
            'version' => $version->version,
            'display_name' => $definition->display_name,
            'status' => $version->status->value,
            'canonical' => $definition->owner_user_id === null,
        ];
    }

    /** @return array<string, mixed> */
    public static function full(PresetVersion $version): array
    {
        return self::summary($version) + [
            'content_hash' => $version->content_hash,
            'engine_version' => $version->engine_version,
            'validation_report' => $version->validation_report,
            'sheet_schema' => self::raw($version, 'sheet_schema'),
            'sheet_ui' => self::raw($version, 'sheet_ui'),
            'rules' => self::raw($version, 'rules'),
        ];
    }

    /** Raw JSONB keeps {} vs [] exactly as imported. */
    private static function raw(PresetVersion $version, string $column): mixed
    {
        return json_decode((string) $version->getRawOriginal($column), false, 64, JSON_THROW_ON_ERROR);
    }
}
