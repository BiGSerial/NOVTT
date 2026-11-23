<?php

declare(strict_types=1);

namespace App\Live;

use App\Http\Problems\ApiProblem;
use App\Models\PresetVersion;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;
use Throwable;

/**
 * Reads an attribute + skill combination from an official sheet (LT-04). Only integer fields
 * declared in the preset layout can be combined; values always come from the server's sheet.
 */
final class SheetCombination
{
    public const MAX_COMPONENTS = 4;

    /** @var array<string, array<string, array{label: string, widget: string}>> */
    private array $fields = [];

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $sheet
     * @return list<array{path: string, label: string, value: int}>
     */
    public function resolve(string $presetVersionId, array $sheet, array $paths): array
    {
        if (count($paths) > self::MAX_COMPONENTS || count(array_unique($paths)) !== count($paths)) {
            throw self::invalid('/paths');
        }
        $fields = $this->numericFields($presetVersionId);
        $components = [];
        foreach ($paths as $i => $path) {
            try {
                $pointer = JsonPointer::parse((string) $path);
            } catch (Throwable) {
                throw self::invalid("/paths/{$i}");
            }
            $field = $fields[$pointer->toString()] ?? null;
            if ($field === null) {
                throw self::invalid("/paths/{$i}");
            }
            $value = self::read($sheet, $pointer->segments);
            $components[] = ['path' => $pointer->toString(), 'label' => $field['label'], 'value' => is_int($value) ? $value : 0];
        }

        return $components;
    }

    /**
     * Integer fields (dots or numbers) declared by the preset layout: the only combinable values.
     *
     * @return array<string, array{label: string, widget: string}>
     */
    public function numericFields(string $presetVersionId): array
    {
        if (isset($this->fields[$presetVersionId])) {
            return $this->fields[$presetVersionId];
        }
        $ui = (array) PresetVersion::query()->whereKey($presetVersionId)->firstOrFail(['id', 'sheet_ui'])->sheet_ui;
        $fields = [];
        foreach ((array) ($ui['sections'] ?? []) as $section) {
            foreach ((array) ($section['elements'] ?? []) as $element) {
                if (($element['kind'] ?? null) === 'field' && in_array($element['widget'] ?? null, ['dot_rating', 'integer'], true) && is_string($element['path'] ?? null)) {
                    $fields[$element['path']] = ['label' => (string) ($element['label'] ?? $element['path']), 'widget' => (string) $element['widget'], 'section' => (string) ($section['label'] ?? '')];
                }
            }
        }

        return $this->fields[$presetVersionId] = $fields;
    }

    /** @param list<string> $tokens */
    private static function read(array $sheet, array $tokens): mixed
    {
        $node = $sheet;
        foreach ($tokens as $token) {
            if (! is_array($node) || ! array_key_exists($token, $node)) {
                return null;
            }
            $node = $node[$token];
        }

        return $node;
    }

    private static function invalid(string $pointer): ApiProblem
    {
        return ApiProblem::validation(new ValidationResult([new ValidationIssue('roll.component_invalid', Severity::Error, [], [$pointer])]), 'roll.component_invalid');
    }
}
