<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use Novtt\Engine\Sheets\FieldAccess;
use Novtt\Engine\Sheets\JsonPointer;

/**
 * Resolved view of a novtt.sheet-ui/1 document: every declared field and collection with its
 * section and effective access (element > section > default). Undeclared paths do not exist
 * for players: they are treated as master_only.
 */
final readonly class SheetLayout
{
    /**
     * @param array<string, array{section: string, access: FieldAccess, kind: 'field'|'collection', item_fields: array<string, FieldAccess>, max_items: int|null}> $elements keyed by pointer
     * @param array<string, string> $sectionLabels
     */
    private function __construct(public array $elements, public array $sectionLabels)
    {
    }

    /** @param array<string, mixed> $sheetUi validated novtt.sheet-ui/1 */
    public static function fromArray(array $sheetUi): self
    {
        $default = FieldAccess::from((string) $sheetUi['default_access']);
        $elements = [];
        $labels = [];
        foreach ($sheetUi['sections'] as $section) {
            $labels[(string) $section['id']] = (string) $section['label'];
            $sectionAccess = isset($section['access']) ? FieldAccess::from((string) $section['access']) : $default;
            foreach ($section['elements'] as $element) {
                $access = isset($element['access']) ? FieldAccess::from((string) $element['access']) : $sectionAccess;
                $itemFields = [];
                foreach ($element['item_fields'] ?? [] as $itemField) {
                    $itemFields[(string) $itemField['field']] = isset($itemField['access'])
                        ? $access->strictest(FieldAccess::from((string) $itemField['access']))
                        : $access;
                }
                $elements[(string) $element['path']] = [
                    'section' => (string) $section['id'],
                    'access' => $access,
                    'kind' => $element['kind'] === 'collection' ? 'collection' : 'field',
                    'item_fields' => $itemFields,
                    'max_items' => isset($element['max_items']) ? (int) $element['max_items'] : null,
                ];
            }
        }

        return new self($elements, $labels);
    }

    /** @return array{section: string, access: FieldAccess, kind: 'field'|'collection', item_fields: array<string, FieldAccess>, max_items: int|null}|null */
    public function element(JsonPointer $path): ?array
    {
        return $this->elements[$path->toString()] ?? null;
    }

    public function accessOf(JsonPointer $path): FieldAccess
    {
        return $this->element($path)['access'] ?? FieldAccess::MasterOnly;
    }

    /** Effective access of one field of a collection item. */
    public function itemFieldAccess(JsonPointer $collection, string $field): FieldAccess
    {
        $element = $this->element($collection);
        if ($element === null || $element['kind'] !== 'collection') {
            return FieldAccess::MasterOnly;
        }

        return $element['item_fields'][$field] ?? FieldAccess::MasterOnly;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->elements);
    }
}
