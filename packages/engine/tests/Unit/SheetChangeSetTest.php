<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use InvalidArgumentException;
use Novtt\Engine\Sheets\ChangeOperation;
use Novtt\Engine\Sheets\ChangeSet;
use Novtt\Engine\Sheets\FieldAccess;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Sheets\OperationType;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SheetChangeSetTest extends TestCase
{
    public function test_pointer_round_trips_escaped_segments(): void
    {
        $pointer = JsonPointer::parse('/attributes/a~1b/c~0d');

        $this->assertSame(['attributes', 'a/b', 'c~d'], $pointer->segments);
        $this->assertSame('/attributes/a~1b/c~0d', $pointer->toString());
        $this->assertTrue($pointer->startsWith(JsonPointer::parse('/attributes')));
        $this->assertFalse(JsonPointer::parse('/attributesx')->startsWith(JsonPointer::parse('/attributes')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPointers(): iterable
    {
        yield 'root replaces whole sheet' => ['', 'pointer.invalid_syntax'];
        yield 'relative' => ['attributes', 'pointer.invalid_syntax'];
        yield 'bad escape' => ['/a~2', 'pointer.invalid_escape'];
        yield 'empty segment' => ['/a//b', 'pointer.empty_segment'];
        yield 'too deep' => [str_repeat('/a', 17), 'pointer.too_deep'];
        yield 'too long' => ['/' . str_repeat('a', 512), 'pointer.too_long'];
    }

    #[DataProvider('invalidPointers')]
    public function test_invalid_pointers_are_rejected(string $pointer, string $code): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($code));
        JsonPointer::parse($pointer);
    }

    public function test_change_set_parses_all_operation_types(): void
    {
        $set = ChangeSet::fromArray([
            'contract' => 'novtt.sheet-change-set/1',
            'base_sheet_revision' => 3,
            'operations' => [
                ['id' => 'op1', 'type' => 'set_field', 'path' => '/attributes/strength', 'value' => 3, 'expected' => 2],
                ['id' => 'op2', 'type' => 'unset_field', 'path' => '/identity/concept'],
                ['id' => 'op3', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm1', 'value' => ['name' => 'X', 'cost' => 1]],
                ['id' => 'op4', 'type' => 'set_item_field', 'path' => '/merits', 'item_id' => 'm1', 'field' => 'cost', 'value' => 2],
                ['id' => 'op5', 'type' => 'remove_item', 'path' => '/merits', 'item_id' => 'm0'],
                ['id' => 'op6', 'type' => 'reorder_items', 'path' => '/merits', 'order' => ['m1', 'm2']],
            ],
        ]);

        $this->assertCount(6, $set->operations);
        $this->assertSame(OperationType::ReorderItems, $set->operations[5]->type);
        $this->assertTrue($set->operations[0]->hasExpected);
        $this->assertFalse($set->operations[1]->hasExpected);
    }

    public function test_hash_is_independent_of_object_key_order(): void
    {
        $a = ChangeSet::fromArray(['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0, 'operations' => [
            ['id' => 'op1', 'type' => 'add_item', 'path' => '/m', 'item_id' => 'i', 'value' => ['a' => 1, 'b' => 2]],
        ]]);
        $b = ChangeSet::fromArray(['operations' => [
            ['value' => ['b' => 2, 'a' => 1], 'item_id' => 'i', 'path' => '/m', 'type' => 'add_item', 'id' => 'op1'],
        ], 'base_sheet_revision' => 0, 'contract' => 'novtt.sheet-change-set/1']);

        $this->assertSame($a->hash(), $b->hash());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->hash());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOperations(): iterable
    {
        yield 'unknown type' => [['id' => 'o', 'type' => 'replace', 'path' => '/a'], 'operation.unknown_type'];
        yield 'subtree replacement via set_field' => [['id' => 'o', 'type' => 'set_field', 'path' => '/attributes', 'value' => ['strength' => 5]], 'operation.subtree_replacement_forbidden'];
        yield 'subtree replacement via set_item_field' => [['id' => 'o', 'type' => 'set_item_field', 'path' => '/m', 'item_id' => 'i', 'field' => 'f', 'value' => [1]], 'operation.subtree_replacement_forbidden'];
        yield 'add_item without id' => [['id' => 'o', 'type' => 'add_item', 'path' => '/m', 'value' => []], 'operation.item_id_mismatch'];
        yield 'add_item scalar' => [['id' => 'o', 'type' => 'add_item', 'path' => '/m', 'item_id' => 'i', 'value' => 3], 'operation.item_must_be_object'];
        yield 'set_field with item id' => [['id' => 'o', 'type' => 'set_field', 'path' => '/a', 'item_id' => 'i', 'value' => 1], 'operation.item_id_mismatch'];
        yield 'unset with value' => [['id' => 'o', 'type' => 'unset_field', 'path' => '/a', 'value' => 1], 'operation.unexpected_value'];
        yield 'reorder duplicates' => [['id' => 'o', 'type' => 'reorder_items', 'path' => '/m', 'order' => ['a', 'a']], 'operation.order_duplicates'];
        yield 'set_item_field without field' => [['id' => 'o', 'type' => 'set_item_field', 'path' => '/m', 'item_id' => 'i', 'value' => 1], 'operation.field_mismatch'];
        yield 'add_item list value' => [['id' => 'o', 'type' => 'add_item', 'path' => '/m', 'item_id' => 'i', 'value' => [1, 2]], 'operation.item_must_be_object'];
        yield 'add_item id inside value' => [['id' => 'o', 'type' => 'add_item', 'path' => '/m', 'item_id' => 'i', 'value' => ['id' => 'x']], 'operation.item_id_in_value'];
        yield 'set_item_field on id' => [['id' => 'o', 'type' => 'set_item_field', 'path' => '/m', 'item_id' => 'i', 'field' => 'id', 'value' => 'y'], 'operation.invalid_field'];
        yield 'bad operation id' => [['id' => '../x', 'type' => 'unset_field', 'path' => '/a'], 'operation.invalid_key'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidOperations')]
    public function test_invalid_operations_are_rejected(array $data, string $code): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($code));
        ChangeOperation::fromArray($data);
    }

    public function test_change_set_rejects_duplicate_ids_and_unknown_contract(): void
    {
        $op = ['id' => 'o', 'type' => 'unset_field', 'path' => '/a'];
        try {
            ChangeSet::fromArray(['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0, 'operations' => [$op, $op]]);
            $this->fail('duplicate ids accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('change_set.duplicate_operation_id', $e->getMessage());
        }
        $this->expectExceptionObject(new InvalidArgumentException('change_set.unsupported_contract'));
        ChangeSet::fromArray(['contract' => 'novtt.sheet-change-set/2', 'base_sheet_revision' => 0, 'operations' => []]);
    }

    public function test_change_set_is_bounded(): void
    {
        $ops = array_map(static fn (int $i): array => ['id' => "o{$i}", 'type' => 'unset_field', 'path' => '/a'], range(1, 201));
        $this->expectExceptionObject(new InvalidArgumentException('change_set.too_many_operations'));
        ChangeSet::fromArray(['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0, 'operations' => $ops]);
    }

    public function test_field_access_player_capabilities(): void
    {
        $this->assertFalse(FieldAccess::Hidden->playerCanRead());
        $this->assertFalse(FieldAccess::MasterOnly->playerCanRead());
        $this->assertTrue(FieldAccess::ReadOnly->playerCanRead());
        $this->assertFalse(FieldAccess::ReadOnly->playerCanPropose());
        $this->assertTrue(FieldAccess::Editable->playerCanPropose());
        $this->assertTrue(FieldAccess::ApprovalRequired->playerCanPropose());
        $this->assertSame(FieldAccess::Hidden, FieldAccess::Editable->strictest(FieldAccess::Hidden));
        $this->assertSame(FieldAccess::MasterOnly, FieldAccess::MasterOnly->strictest(FieldAccess::ReadOnly));
    }

    public function test_validation_result_contract_and_filtering(): void
    {
        $result = new ValidationResult([
            new ValidationIssue('limit.max', Severity::Error, ['op1'], ['/attributes/strength'], ['max' => 5]),
            new ValidationIssue('budget.unspent', Severity::Warning, [], ['/budgets/initial']),
        ]);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->filter(static fn (ValidationIssue $i): bool => $i->severity === Severity::Warning)->isValid());
        $array = json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true);
        $this->assertSame('novtt.validation-result/1', $array['contract']);
        $this->assertSame(['max' => 5], $array['issues'][0]['params']);
    }
}
