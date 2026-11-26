<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Sheets\SheetMigration;
use PHPUnit\Framework\TestCase;

/** Adapting a sheet to a newer version of its preset's schema: keep what is valid, report the rest. */
final class SheetMigrationTest extends TestCase
{
    private const TARGET = [
        'type' => 'object', 'additionalProperties' => false,
        'properties' => [
            'identity' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string', 'maxLength' => 10]]],
            'origin' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['lineage' => ['type' => 'string', 'enum' => ['aurora', 'nevoa']]]],
            'attributes' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'forca' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'vigor' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
            ]],
            'items' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'object', 'required' => ['id', 'name'], 'additionalProperties' => false, 'properties' => [
                'id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'qty' => ['type' => 'integer', 'minimum' => 1],
            ]]],
        ],
    ];

    public function test_valid_values_are_kept_and_each_drop_is_reported_with_its_reason(): void
    {
        $result = SheetMigration::migrate([
            'identity' => ['name' => 'Iara', 'concept' => 'Batedora'],
            'origin' => ['lineage' => 'brasa'],
            'attributes' => ['forca' => 3, 'vigor' => 5],
            'items' => [['id' => 'a', 'name' => 'Corda', 'qty' => 0], ['id' => 'b'], ['id' => 'c', 'name' => 'Tocha'], ['id' => 'd', 'name' => 'Faca']],
            'skills' => ['luta' => 2],
        ], self::TARGET);

        $this->assertSame(['name' => 'Iara'], $result['sheet']['identity']);
        $this->assertSame(['forca' => 3], $result['sheet']['attributes']);
        $this->assertSame([['id' => 'a', 'name' => 'Corda'], ['id' => 'c', 'name' => 'Tocha']], $result['sheet']['items']);
        $this->assertArrayNotHasKey('skills', $result['sheet']);
        $reasons = array_column($result['dropped'], 'reason', 'path');
        $this->assertSame([
            '/identity/concept' => 'field_removed',
            '/origin/lineage' => 'option_removed',
            '/attributes/vigor' => 'out_of_range',
            '/items/0/qty' => 'out_of_range',
            '/items/1' => 'incomplete',
            '/items/2' => 'too_many_items',
            '/skills' => 'field_removed',
        ], $reasons);
    }

    public function test_ledger_entries_survive_only_where_the_paid_level_still_stands(): void
    {
        $result = SheetMigration::migrate([
            'attributes' => ['forca' => 3, 'vigor' => 5],
            '_ledger' => [
                ['path' => '/attributes/forca', 'level' => 2, 'budget' => 'p', 'cost' => 1],
                ['path' => '/attributes/forca', 'level' => 3, 'budget' => 'p', 'cost' => 1],
                ['path' => '/attributes/vigor', 'level' => 2, 'budget' => 'p', 'cost' => 1],
                ['path' => '/skills/luta', 'level' => 1, 'budget' => 's', 'cost' => 1],
            ],
        ], self::TARGET);

        $this->assertSame(['/attributes/forca', '/attributes/forca'], array_column($result['sheet']['_ledger'], 'path'));
    }

    public function test_a_sheet_already_valid_comes_back_unchanged(): void
    {
        $sheet = ['identity' => ['name' => 'Iara'], 'attributes' => ['forca' => 2]];
        $result = SheetMigration::migrate($sheet, self::TARGET);
        $this->assertSame($sheet, $result['sheet']);
        $this->assertSame([], $result['dropped']);
    }
}
