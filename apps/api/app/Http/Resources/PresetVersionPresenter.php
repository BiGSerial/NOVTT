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
        ] + self::metadata($version);
    }

    /**
     * Identity, rules revision, licence, rights and coverage shown to the user before choosing a
     * preset (ADR-002 criterion 5). manifest/1 carries no rights metadata: those fields are null.
     *
     * @return array<string, mixed>
     */
    public static function metadata(PresetVersion $version): array
    {
        $manifest = (array) $version->manifest;
        $v2 = ($manifest['contract'] ?? null) === 'novtt.preset-manifest/2';
        $license = $v2 ? (array) $manifest['license'] : ['identifier' => is_string($manifest['license'] ?? null) ? $manifest['license'] : null];
        $taxonomy = [];
        foreach ((array) ($version->compendium['taxonomy'] ?? []) as $kind) {
            $taxonomy[] = ['kind' => $kind['kind'], 'labels' => (object) $kind['labels'], 'parent_kind' => $kind['parent_kind'] ?? null];
        }

        return [
            'rules_revision' => $manifest['rules_revision'] ?? null,
            'source_version' => $manifest['source_version'] ?? null,
            'errata_reference' => $manifest['errata_reference'] ?? null,
            'locale' => $manifest['locale'] ?? null,
            'license' => [
                'identifier' => $license['identifier'] ?? null,
                'attribution' => $license['attribution'] ?? null,
                'source_url' => $license['source_url'] ?? null,
            ],
            'rights_status' => $version->rights_status,
            'source_authenticity' => $v2 ? ($manifest['provenance']['source_authenticity'] ?? null) : null,
            'coverage' => $manifest['coverage'] ?? null,
            'limitations' => array_values((array) ($manifest['limitations'] ?? [])),
            'taxonomy' => $taxonomy,
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
            // Short functional labels and original summaries for choice cards; provenance stays server-side.
            'compendium' => array_map(static fn (array $item): array => [
                'canonical_id' => $item['canonical_id'],
                'kind' => $item['kind'],
                'parent_id' => $item['parent_id'] ?? null,
                'labels' => (object) $item['labels'],
                'summary' => $item['summary'] ?? null,
            ], array_values((array) ($version->compendium['items'] ?? []))),
        ];
    }

    /** Raw JSONB keeps {} vs [] exactly as imported. */
    private static function raw(PresetVersion $version, string $column): mixed
    {
        return json_decode((string) $version->getRawOriginal($column), false, 64, JSON_THROW_ON_ERROR);
    }
}
