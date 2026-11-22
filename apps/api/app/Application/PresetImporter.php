<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\PresetVersionStatus;
use App\Http\Problems\ApiProblem;
use App\Infrastructure\Validation\JsonDocument;
use App\Models\PresetDefinition;
use App\Models\PresetFamily;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use JsonException;
use Novtt\Engine\Presets\PresetCompatibility;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Imports a preset package (SPEC-PRESET-AUTHORING-001). Three stages: JSON syntax, structural
 * contracts, engine compatibility. Transactional, bounded, never executes content, never
 * overwrites an existing (family, variant, version).
 */
final readonly class PresetImporter
{
    public function __construct(
        private StructuralValidator $structure,
        private PresetCompatibility $compatibility,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Stage 1. Parses the raw body; the result keeps the object/list distinction.
     *
     * @return array{0: array<string, mixed>, 1: object}
     */
    public function parse(string $raw): array
    {
        if (strlen($raw) > (int) config('novtt.preset_import_max_bytes')) {
            throw new ApiProblem(413, 'preset.too_large');
        }
        try {
            $objects = JsonDocument::decode($raw, 64);
            $arrays = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('json.syntax', Severity::Error, [], [''])]), 'preset.invalid');
        }
        if (!is_array($arrays) || !is_object($objects)) {
            throw ApiProblem::validation(new ValidationResult([new ValidationIssue('json.root_must_be_object', Severity::Error, [], [''])]), 'preset.invalid');
        }

        return [$arrays, $objects];
    }

    /**
     * Stages 2 and 3.
     *
     * @param array<string, mixed> $package
     */
    public function validate(array $package): ValidationResult
    {
        $issues = [];
        foreach (['manifest', 'sheet_schema', 'sheet_ui', 'rules'] as $part) {
            if (!is_array($package[$part] ?? null)) {
                $issues[] = new ValidationIssue('package.missing_part', Severity::Error, [], ['/' . $part]);
            }
        }
        foreach (array_diff(array_keys($package), ['manifest', 'sheet_schema', 'sheet_ui', 'rules']) as $unknown) {
            $issues[] = new ValidationIssue('package.unknown_part', Severity::Error, [], ['/' . $unknown]);
        }
        if ($issues !== []) {
            return new ValidationResult($issues);
        }
        $result = ValidationResult::ok();
        foreach (['manifest' => 'novtt.preset-manifest/1', 'sheet_ui' => 'novtt.sheet-ui/1', 'rules' => 'novtt.rules/1'] as $part => $contract) {
            $partResult = $this->structure->validateContract($package[$part], $contract);
            $result = $result->merge(new ValidationResult(array_map(
                static fn (ValidationIssue $i): ValidationIssue => new ValidationIssue($i->ruleId, $i->severity, [], array_map(static fn (string $p): string => '/' . $part . $p, $i->paths), $i->params),
                $partResult->issues,
            )));
        }
        if (!$result->isValid()) {
            return $result;
        }

        return $this->compatibility->check($package['manifest'], $package['sheet_schema'], $package['sheet_ui'], $package['rules']);
    }

    /**
     * Imports into the actor's personal library (owner set) or, for team tooling, into the
     * canonical catalogue (owner null). Returns [version, created].
     *
     * @param array<string, mixed> $package decoded with assoc arrays
     * @param object $objects the same package decoded as objects (keeps {} vs [])
     * @return array{0: PresetVersion, 1: bool}
     */
    public function import(User $actor, array $package, object $objects, bool $canonical = false): array
    {
        $result = $this->validate($package);
        if (!$result->isValid()) {
            throw ApiProblem::validation($result, 'preset.invalid');
        }
        $manifest = $package['manifest'];
        $hash = hash('sha256', CanonicalJson::encode($package));
        $ownerId = $canonical ? null : $actor->getKey();

        return DB::transaction(function () use ($actor, $manifest, $objects, $hash, $ownerId, $canonical, $result): array {
            $family = $this->firstOrCreateFamily($ownerId, (string) $manifest['family_id'], (string) $manifest['display_name']);
            // createOrFirst uses a savepoint, so concurrent imports converge instead of failing.
            $definition = PresetDefinition::query()->createOrFirst(
                ['family_id' => $family->getKey(), 'variant_slug' => $manifest['variant_id']],
                ['owner_user_id' => $ownerId, 'display_name' => $manifest['display_name']],
            );

            $existing = PresetVersion::query()->where('definition_id', $definition->getKey())->where('version', $manifest['version'])->first();
            if ($existing !== null) {
                if ($existing->content_hash !== $hash) {
                    throw ApiProblem::conflict('preset.version_exists', null, 'Publish corrections as a new version.');
                }

                return [$existing, false];
            }

            $version = PresetVersion::query()->create([
                'definition_id' => $definition->getKey(),
                'version' => $manifest['version'],
                'status' => $canonical ? PresetVersionStatus::Published : PresetVersionStatus::EngineCompatible,
                'content_hash' => $hash,
                'manifest' => $objects->manifest,
                'sheet_schema' => $objects->sheet_schema,
                'sheet_ui' => $objects->sheet_ui,
                'rules' => $objects->rules,
                'validation_report' => $result->toArray(),
                'engine_version' => PresetCompatibility::ENGINE_VERSION,
                'imported_by' => $actor->getKey(),
                'published_at' => $canonical ? now() : null,
            ]);
            if (!$canonical) {
                DB::table('user_preset_library')->insertOrIgnore([
                    'user_id' => $actor->getKey(), 'preset_version_id' => $version->getKey(), 'added_at' => now(),
                ]);
            }
            $this->audit->record($actor, null, $canonical ? 'preset.published' : 'preset.imported', $version, [
                'family_id' => $manifest['family_id'], 'variant_id' => $manifest['variant_id'], 'version' => $manifest['version'], 'content_hash' => $hash,
            ]);

            // Reload: class casts cache the stdClass trees that were written.
            return [$version->refresh(), true];
        });
    }

    private function firstOrCreateFamily(?string $ownerId, string $slug, string $name): PresetFamily
    {
        return PresetFamily::query()->createOrFirst(['owner_user_id' => $ownerId, 'slug' => $slug], ['display_name' => $name]);
    }
}
