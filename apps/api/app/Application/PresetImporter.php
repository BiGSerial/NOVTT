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
use Novtt\Engine\Presets\PublicationGate;
use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Imports a preset package (SPEC-PRESET-AUTHORING-001, ADR 0012). Three stages: JSON syntax,
 * structural contracts (chosen by each part's declared `contract`), engine compatibility.
 * Canonical publication additionally passes the rights gate. Transactional, bounded, never
 * executes content, never overwrites an existing (family, variant, version).
 */
final readonly class PresetImporter
{
    public const REQUIRED_PARTS = ['manifest', 'sheet_schema', 'sheet_ui', 'rules'];
    public const OPTIONAL_PARTS = ['compendium'];

    /** Accepted contracts per part; the first one is used to report a missing or unknown value. */
    private const CONTRACTS = [
        'manifest' => ['novtt.preset-manifest/2', 'novtt.preset-manifest/1'],
        'sheet_ui' => ['novtt.sheet-ui/1'],
        'rules' => ['novtt.rules/2', 'novtt.rules/1'],
        'compendium' => ['novtt.compendium/1'],
    ];

    public function __construct(
        private StructuralValidator $structure,
        private PresetCompatibility $compatibility,
        private AuditTrail $audit,
        private PublicationGate $gate = new PublicationGate(),
    ) {
    }

    /**
     * Reads a preset folder (manifest.json, sheet.schema.json, sheet.ui.json, rules.json and, when
     * present, compendium.json) into the raw package body accepted by parse(). Returns null and the
     * missing file name on failure.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function readDirectory(string $directory): array
    {
        $dir = rtrim($directory, '/');
        $parts = [];
        foreach (['manifest' => 'manifest.json', 'sheet_schema' => 'sheet.schema.json', 'sheet_ui' => 'sheet.ui.json', 'rules' => 'rules.json'] as $key => $file) {
            if (!is_file("{$dir}/{$file}")) {
                return [null, $file];
            }
            $parts[] = json_encode($key) . ':' . file_get_contents("{$dir}/{$file}");
        }
        if (is_file("{$dir}/compendium.json")) {
            $parts[] = '"compendium":' . file_get_contents("{$dir}/compendium.json");
        }

        return ['{' . implode(',', $parts) . '}', null];
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
        foreach (self::REQUIRED_PARTS as $part) {
            if (!is_array($package[$part] ?? null)) {
                $issues[] = new ValidationIssue('package.missing_part', Severity::Error, [], ['/' . $part]);
            }
        }
        foreach (self::OPTIONAL_PARTS as $part) {
            if (array_key_exists($part, $package) && !is_array($package[$part])) {
                $issues[] = new ValidationIssue('package.invalid_part', Severity::Error, [], ['/' . $part]);
            }
        }
        foreach (array_diff(array_keys($package), [...self::REQUIRED_PARTS, ...self::OPTIONAL_PARTS]) as $unknown) {
            $issues[] = new ValidationIssue('package.unknown_part', Severity::Error, [], ['/' . $unknown]);
        }
        if ($issues !== []) {
            return new ValidationResult($issues);
        }
        $result = ValidationResult::ok();
        foreach (self::CONTRACTS as $part => $accepted) {
            if (!isset($package[$part])) {
                continue;
            }
            $declared = $package[$part]['contract'] ?? null;
            $contract = in_array($declared, $accepted, true) ? $declared : $accepted[0];
            $partResult = $this->structure->validateContract($package[$part], $contract);
            $result = $result->merge(new ValidationResult(array_map(
                static fn (ValidationIssue $i): ValidationIssue => new ValidationIssue($i->ruleId, $i->severity, [], array_map(static fn (string $p): string => '/' . $part . $p, $i->paths), $i->params),
                $partResult->issues,
            )));
        }
        if (!$result->isValid()) {
            return $result;
        }

        return $this->compatibility->check($package['manifest'], $package['sheet_schema'], $package['sheet_ui'], $package['rules'], $package['compendium'] ?? null);
    }

    /** Rights status recorded for a version: from manifest/2, or `draft` (no rights metadata). @param array<string, mixed> $manifest */
    public static function rightsStatus(array $manifest): string
    {
        $status = ($manifest['contract'] ?? null) === 'novtt.preset-manifest/2' ? ($manifest['license']['rights_status'] ?? null) : null;

        return in_array($status, PublicationGate::RIGHTS_STATES, true) ? $status : 'draft';
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
        if ($canonical) {
            // Technical validity is not a licence: the public catalogue needs recorded rights.
            $rights = $this->gate->check($manifest, $package['compendium'] ?? null);
            if (!$rights->isValid()) {
                throw ApiProblem::validation($rights, 'preset.rights_blocked');
            }
        }
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
                'compendium' => $objects->compendium ?? null,
                'rights_status' => self::rightsStatus($manifest),
                'rights_updated_at' => now(),
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
                'rules_revision' => $manifest['rules_revision'] ?? null, 'rights_status' => self::rightsStatus($manifest),
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
