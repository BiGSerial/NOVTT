<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Third validation stage of a preset import: is the structurally valid package actually
 * executable by this engine version? (SPEC-PRESET-AUTHORING-001: "JSON Schema válido não é
 * compatibilidade da engine".) Inputs must already satisfy their contracts.
 */
final class PresetCompatibility
{
    public const ENGINE_VERSION = '0.1.0';

    private const ALLOWED_SCHEMA_KEYWORDS = [
        '$schema', 'title', 'description', 'type', 'properties', 'additionalProperties', 'required',
        'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'enum', 'default', 'items',
        'maxItems', 'minItems', 'maxProperties', 'propertyNames', 'const', 'format',
    ];
    private const MAX_SCHEMA_NODES = 5000;

    /**
     * @param array<string, mixed> $manifest novtt.preset-manifest/1
     * @param array<string, mixed> $sheetSchema
     * @param array<string, mixed> $sheetUi novtt.sheet-ui/1
     * @param array<string, mixed> $rules novtt.rules/1
     */
    public function check(array $manifest, array $sheetSchema, array $sheetUi, array $rules): ValidationResult
    {
        $issues = [];
        if (version_compare((string) $manifest['engine']['min_version'], self::ENGINE_VERSION, '>')) {
            $issues[] = $this->error('engine.version_unsupported', ['/manifest/engine/min_version'], ['engine_version' => self::ENGINE_VERSION]);
        }
        $this->checkSchemaSubset($sheetSchema, '/sheet_schema', $issues, 0);
        if (SchemaNavigator::type($sheetSchema) !== 'object') {
            $issues[] = $this->error('schema.root_must_be_object', ['/sheet_schema']);
        }
        if ($issues !== []) {
            return new ValidationResult($issues);
        }

        $schema = new SchemaNavigator($sheetSchema);
        $this->checkUi($schema, $sheetUi, $issues);
        $this->checkRules($schema, $rules, $issues);

        return new ValidationResult($issues);
    }

    /** @param list<ValidationIssue> $issues */
    private function checkSchemaSubset(mixed $node, string $at, array &$issues, int $count): int
    {
        if (!is_array($node)) {
            return $count;
        }
        if (++$count > self::MAX_SCHEMA_NODES) {
            $issues[] = $this->error('schema.too_complex', [$at], ['max_nodes' => self::MAX_SCHEMA_NODES]);

            return $count;
        }
        foreach ($node as $keyword => $value) {
            if (!in_array($keyword, self::ALLOWED_SCHEMA_KEYWORDS, true)) {
                $issues[] = $this->error('UNSUPPORTED_CAPABILITY', [$at . '/' . $keyword], ['keyword' => (string) $keyword]);
                continue;
            }
            if ($keyword === 'properties' && is_array($value)) {
                foreach ($value as $name => $child) {
                    if (str_starts_with((string) $name, '_')) {
                        $issues[] = $this->error('schema.reserved_property', [$at . '/properties/' . $name]);
                    }
                    $count = $this->checkSchemaSubset($child, $at . '/properties/' . $name, $issues, $count);
                }
            } elseif (in_array($keyword, ['items', 'additionalProperties', 'propertyNames'], true)) {
                $count = $this->checkSchemaSubset($value, $at . '/' . $keyword, $issues, $count);
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $sheetUi @param list<ValidationIssue> $issues */
    private function checkUi(SchemaNavigator $schema, array $sheetUi, array &$issues): void
    {
        $sections = [];
        $paths = [];
        foreach ($sheetUi['sections'] as $s => $section) {
            if (isset($sections[$section['id']])) {
                $issues[] = $this->error('ui.duplicate_section', ["/sheet_ui/sections/{$s}/id"], ['section_id' => $section['id']]);
            }
            $sections[$section['id']] = true;
            foreach ($section['elements'] as $e => $element) {
                $at = "/sheet_ui/sections/{$s}/elements/{$e}";
                $path = (string) $element['path'];
                if (isset($paths[$path])) {
                    $issues[] = $this->error('ui.duplicate_path', [$at], ['path' => $path]);
                }
                $paths[$path] = true;
                $pointer = JsonPointer::parse($path);
                if ($element['kind'] === 'field') {
                    if (!$schema->isScalar($pointer)) {
                        $issues[] = $this->error('ui.path_not_scalar_field', [$at], ['path' => $path]);
                    } elseif ($element['widget'] === 'dot_rating' && !$schema->isInteger($pointer)) {
                        $issues[] = $this->error('ui.dot_rating_requires_integer', [$at], ['path' => $path]);
                    }
                    continue;
                }
                if (!$schema->isCollection($pointer)) {
                    $issues[] = $this->error('ui.path_not_collection', [$at], ['path' => $path]);
                    continue;
                }
                foreach ($element['item_fields'] as $f => $itemField) {
                    if ($itemField['field'] === 'id' || $schema->itemField($pointer, (string) $itemField['field']) === null) {
                        $issues[] = $this->error('ui.item_field_unknown', ["{$at}/item_fields/{$f}"], ['field' => $itemField['field']]);
                    }
                }
            }
        }
        // A declared element must not be nested inside another declared element.
        foreach (array_keys($paths) as $a) {
            foreach (array_keys($paths) as $b) {
                if ($a !== $b && str_starts_with((string) $b, $a . '/')) {
                    $issues[] = $this->error('ui.nested_elements', ['/sheet_ui'], ['outer' => $a, 'inner' => $b]);
                }
            }
        }
    }

    /** @param array<string, mixed> $rules @param list<ValidationIssue> $issues */
    private function checkRules(SchemaNavigator $schema, array $rules, array &$issues): void
    {
        $budgets = [];
        foreach ($rules['budgets'] as $i => $budget) {
            if (isset($budgets[$budget['id']])) {
                $issues[] = $this->error('rules.duplicate_id', ["/rules/budgets/{$i}"], ['id' => $budget['id']]);
            }
            $budgets[$budget['id']] = true;
        }
        $ids = [];
        foreach (['costs', 'phase_limits', 'dependencies'] as $group) {
            foreach ($rules[$group] ?? [] as $i => $rule) {
                $at = "/rules/{$group}/{$i}";
                $key = $group . ':' . $rule['id'];
                if (isset($ids[$key])) {
                    $issues[] = $this->error('rules.duplicate_id', [$at], ['id' => $rule['id']]);
                }
                $ids[$key] = true;
                if (isset($rule['budget']) && !isset($budgets[$rule['budget']])) {
                    $issues[] = $this->error('rules.unknown_budget', [$at], ['budget' => $rule['budget']]);
                }
                foreach ($rule['targets'] ?? [] as $target) {
                    $expanded = $schema->expand((string) $target);
                    if ($expanded === []) {
                        $issues[] = $this->error('rules.unknown_target', [$at], ['target' => $target]);
                    }
                    foreach ($expanded as $pointer) {
                        if (!$schema->isInteger($pointer)) {
                            $issues[] = $this->error('rules.target_not_integer', [$at], ['target' => $pointer->toString()]);
                        }
                    }
                }
                if ($group === 'dependencies') {
                    foreach ([$rule['path'], ...$rule['requires']] as $path) {
                        if ($schema->at(JsonPointer::parse((string) $path)) === null) {
                            $issues[] = $this->error('rules.unknown_target', [$at], ['target' => $path]);
                        }
                    }
                }
            }
        }
        $cycle = $this->findCycle($rules['dependencies'] ?? []);
        if ($cycle !== null) {
            $issues[] = $this->error('rules.dependency_cycle', ['/rules/dependencies'], ['cycle' => implode(' -> ', $cycle)]);
        }
    }

    /**
     * @param list<array{path: string, requires: list<string>}> $dependencies
     * @return list<string>|null
     */
    private function findCycle(array $dependencies): ?array
    {
        $graph = [];
        foreach ($dependencies as $dependency) {
            foreach ($dependency['requires'] as $required) {
                $graph[$dependency['path']][] = $required;
            }
        }
        $state = [];
        $stack = [];
        $visit = function (string $node) use (&$visit, &$state, &$stack, $graph): ?array {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    return [...array_slice($stack, (int) array_search($next, $stack, true)), $next];
                }
                if (($state[$next] ?? 0) === 0 && ($found = $visit($next)) !== null) {
                    return $found;
                }
            }
            array_pop($stack);
            $state[$node] = 2;

            return null;
        };
        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0 && ($found = $visit((string) $node)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param list<string> $paths @param array<string, scalar|null> $params */
    private function error(string $rule, array $paths, array $params = []): ValidationIssue
    {
        return new ValidationIssue($rule, Severity::Error, [], $paths, $params);
    }
}
