<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

use InvalidArgumentException;

/** novtt.sheet-change-set/1: ordered, bounded list of operations with unique ids. */
final readonly class ChangeSet
{
    public const CONTRACT = 'novtt.sheet-change-set/1';
    public const MAX_OPERATIONS = 200;

    /** @param list<ChangeOperation> $operations */
    public function __construct(public int $baseSheetRevision, public array $operations)
    {
        if ($baseSheetRevision < 0) {
            throw new InvalidArgumentException('change_set.invalid_base_revision');
        }
        if (count($operations) > self::MAX_OPERATIONS) {
            throw new InvalidArgumentException('change_set.too_many_operations');
        }
        $keys = array_map(static fn (ChangeOperation $o): string => $o->key, $operations);
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException('change_set.duplicate_operation_id');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['contract'] ?? null) !== self::CONTRACT) {
            throw new InvalidArgumentException('change_set.unsupported_contract');
        }
        $operations = $data['operations'] ?? null;
        if (!is_array($operations) || !array_is_list($operations)) {
            throw new InvalidArgumentException('change_set.invalid_operations');
        }

        return new self(
            (int) ($data['base_sheet_revision'] ?? -1),
            array_map(static fn (mixed $o): ChangeOperation => ChangeOperation::fromArray(is_array($o) ? $o : []), $operations),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract' => self::CONTRACT,
            'base_sheet_revision' => $this->baseSheetRevision,
            'operations' => array_map(static fn (ChangeOperation $o): array => $o->toArray(), $this->operations),
        ];
    }

    /** Deterministic SHA-256 of the canonical form, used to bind idempotency keys to payloads. */
    public function hash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->toArray()));
    }
}
