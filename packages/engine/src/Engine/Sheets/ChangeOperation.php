<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

use InvalidArgumentException;

/**
 * One typed operation of a novtt.sheet-change-set/1 document.
 *
 * - set_field / unset_field: `path` targets a scalar field (never a collection or the root).
 * - add_item: `path` targets a collection (array of objects with unique `id`); `item_id` is a
 *   new stable id; `value` is the item without `id`.
 * - set_item_field / remove_item: `path` targets a collection; `item_id` is an existing item;
 *   set_item_field also carries `field` (a single key) and `value`.
 * - reorder_items: `path` targets a collection; `order` lists every existing item id once.
 *
 * `expected` is an optional precondition (value seen by the author) used for conflict detection.
 */
final readonly class ChangeOperation
{
    public const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';
    public const ITEM_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    /**
     * @param list<string>|null $order
     */
    public function __construct(
        public string $key,
        public OperationType $type,
        public JsonPointer $path,
        public ?string $itemId = null,
        public ?string $field = null,
        public mixed $value = null,
        public ?array $order = null,
        public bool $hasExpected = false,
        public mixed $expected = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('operation.invalid_key');
        }
        $needsItem = in_array($type, [OperationType::AddItem, OperationType::SetItemField, OperationType::RemoveItem], true);
        if ($needsItem !== ($itemId !== null)) {
            throw new InvalidArgumentException('operation.item_id_mismatch');
        }
        if ($itemId !== null && preg_match(self::ITEM_ID_PATTERN, $itemId) !== 1) {
            throw new InvalidArgumentException('operation.invalid_item_id');
        }
        if (($type === OperationType::SetItemField) !== ($field !== null)) {
            throw new InvalidArgumentException('operation.field_mismatch');
        }
        if ($field !== null && (preg_match(self::KEY_PATTERN, $field) !== 1 || $field === 'id')) {
            throw new InvalidArgumentException('operation.invalid_field');
        }
        if (($type === OperationType::ReorderItems) !== ($order !== null)) {
            throw new InvalidArgumentException('operation.order_mismatch');
        }
        if ($order !== null) {
            if ($order !== array_values(array_unique($order))) {
                throw new InvalidArgumentException('operation.order_duplicates');
            }
            foreach ($order as $id) {
                if (!is_string($id) || preg_match(self::ITEM_ID_PATTERN, $id) !== 1) {
                    throw new InvalidArgumentException('operation.invalid_item_id');
                }
            }
        }
        $carriesValue = in_array($type, [OperationType::SetField, OperationType::AddItem, OperationType::SetItemField], true);
        if (!$carriesValue && $value !== null) {
            throw new InvalidArgumentException('operation.unexpected_value');
        }
        // add_item values are objects; scalar fields never receive objects (no subtree replacement).
        if ($type === OperationType::AddItem && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
            throw new InvalidArgumentException('operation.item_must_be_object');
        }
        if ($type === OperationType::AddItem && array_key_exists('id', $value)) {
            throw new InvalidArgumentException('operation.item_id_in_value');
        }
        if (in_array($type, [OperationType::SetField, OperationType::SetItemField], true) && is_array($value)) {
            throw new InvalidArgumentException('operation.subtree_replacement_forbidden');
        }
    }

    /**
     * Builds an operation from a decoded novtt.sheet-change-set/1 entry. Structure is assumed
     * already validated against the JSON Schema; this enforces the domain invariants.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = OperationType::tryFrom((string) ($data['type'] ?? ''))
            ?? throw new InvalidArgumentException('operation.unknown_type');

        return new self(
            key: (string) ($data['id'] ?? ''),
            type: $type,
            path: JsonPointer::parse((string) ($data['path'] ?? '')),
            itemId: isset($data['item_id']) ? (string) $data['item_id'] : null,
            field: isset($data['field']) ? (string) $data['field'] : null,
            value: $data['value'] ?? null,
            order: isset($data['order']) && is_array($data['order']) ? array_values($data['order']) : null,
            hasExpected: array_key_exists('expected', $data),
            expected: $data['expected'] ?? null,
        );
    }

    /** Canonical form used for hashing and idempotency comparisons. @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['id' => $this->key, 'type' => $this->type->value, 'path' => $this->path->toString()];
        if ($this->itemId !== null) {
            $out['item_id'] = $this->itemId;
        }
        if ($this->field !== null) {
            $out['field'] = $this->field;
        }
        if (in_array($this->type, [OperationType::SetField, OperationType::AddItem, OperationType::SetItemField], true)) {
            $out['value'] = $this->value;
        }
        if ($this->order !== null) {
            $out['order'] = $this->order;
        }
        if ($this->hasExpected) {
            $out['expected'] = $this->expected;
        }

        return $out;
    }
}
