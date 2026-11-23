<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use Novtt\Engine\Sheets\JsonPointer;

/**
 * Resolves the sub-schema addressed by a sheet pointer through `properties` (no $ref, no
 * combinators): preset sheet schemas are required to declare their fields explicitly.
 */
final readonly class SchemaNavigator
{
    /** @param array<string, mixed> $schema */
    public function __construct(private array $schema)
    {
    }

    /** @return array<string, mixed>|null */
    public function at(JsonPointer $pointer): ?array
    {
        $node = $this->schema;
        foreach ($pointer->segments as $segment) {
            $next = $node['properties'][$segment] ?? null;
            if (!is_array($next)) {
                return null;
            }
            $node = $next;
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    public static function type(array $node): ?string
    {
        $type = $node['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function isScalar(JsonPointer $pointer): bool
    {
        $node = $this->at($pointer);

        return $node !== null && in_array(self::type($node), ['string', 'integer', 'number', 'boolean'], true);
    }

    public function isInteger(JsonPointer $pointer): bool
    {
        $node = $this->at($pointer);

        return $node !== null && self::type($node) === 'integer';
    }

    /** Collections are arrays of objects with a required string `id`. */
    public function isCollection(JsonPointer $pointer): bool
    {
        $node = $this->at($pointer);
        if ($node === null || self::type($node) !== 'array' || !is_array($node['items'] ?? null)) {
            return false;
        }
        $items = $node['items'];

        return self::type($items) === 'object'
            && (self::type((array) ($items['properties']['id'] ?? [])) === 'string')
            && in_array('id', (array) ($items['required'] ?? []), true);
    }

    /** @return array<string, mixed>|null */
    public function itemField(JsonPointer $collection, string $field): ?array
    {
        $node = $this->at($collection);
        $property = $node['items']['properties'][$field] ?? null;

        return is_array($property) ? $property : null;
    }

    /**
     * Expands a rules target ("/a/b" or "/a/*") into concrete declared pointers.
     *
     * @return list<JsonPointer>
     */
    public function expand(string $target): array
    {
        if (!str_ends_with($target, '/*')) {
            $pointer = JsonPointer::parse($target);

            return $this->at($pointer) !== null ? [$pointer] : [];
        }
        $parent = JsonPointer::parse(substr($target, 0, -2));
        $node = $this->at($parent);
        $out = [];
        foreach (array_keys((array) ($node['properties'] ?? [])) as $child) {
            $out[] = $parent->append((string) $child);
        }

        return $out;
    }
}
