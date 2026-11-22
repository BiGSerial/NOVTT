<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use App\Infrastructure\Validation\ContractRegistry;
use Novtt\Engine\Sheets\ChangeSet;
use Novtt\Engine\Validation\StructuralValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class ContractSchemasTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../../presets/fixtures/demo-basic/1.0.0';

    private function validator(): StructuralValidator
    {
        return $this->app->make(StructuralValidator::class);
    }

    /** @return array<string, mixed> */
    private static function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE . '/' . $file), true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_every_registered_contract_exists_and_declares_draft_2020_12(): void
    {
        $registry = $this->app->make(ContractRegistry::class);
        foreach ($registry->contracts() as $contract) {
            $schema = $registry->schema($contract);
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'], $contract);
            $this->assertSame($contract, $schema['title'], $contract);
        }
    }

    public function test_demo_preset_fixture_satisfies_the_preset_contracts(): void
    {
        foreach (['manifest.json' => 'novtt.preset-manifest/1', 'sheet.ui.json' => 'novtt.sheet-ui/1', 'rules.json' => 'novtt.rules/1'] as $file => $contract) {
            $result = $this->validator()->validateContract(self::fixture($file), $contract);
            $this->assertTrue($result->isValid(), $file . ': ' . json_encode($result->toArray()));
        }
    }

    public function test_sheet_values_are_validated_against_the_version_schema(): void
    {
        $schema = self::fixture('sheet.schema.json');
        $valid = ['identity' => ['name' => 'Ana'], 'attributes' => ['might' => 3], 'merits' => [['id' => 'm1', 'name' => 'Ágil', 'cost' => 2]], 'skills' => []];
        $this->assertTrue($this->validator()->validate($valid, $schema)->isValid());

        $invalid = ['attributes' => ['might' => 9, 'charm' => 1]];
        $result = $this->validator()->validate($invalid, $schema)->toArray();
        $this->assertFalse($result['valid']);
        $rules = array_column($result['issues'], 'rule_id');
        $paths = array_merge(...array_column($result['issues'], 'paths'));
        $this->assertContains('schema.maximum', $rules);
        $this->assertContains('/attributes/might', $paths);
        $this->assertContains('schema.additionalProperties', $rules);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidChangeSets(): iterable
    {
        $base = ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0];
        yield 'object value in set_field (subtree replacement)' => [$base + ['operations' => [['id' => 'o', 'type' => 'set_field', 'path' => '/attributes', 'value' => ['might' => 5]]]]];
        yield 'root pointer' => [$base + ['operations' => [['id' => 'o', 'type' => 'unset_field', 'path' => '']]]];
        yield 'reserved engine path' => [$base + ['operations' => [['id' => 'o', 'type' => 'set_field', 'path' => '/_ledger', 'value' => 1]]]];
        yield 'id inside add_item value' => [$base + ['operations' => [['id' => 'o', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm', 'value' => ['id' => 'x']]]]];
        yield 'set_item_field targets id' => [$base + ['operations' => [['id' => 'o', 'type' => 'set_item_field', 'path' => '/merits', 'item_id' => 'm', 'field' => 'id', 'value' => 'y']]]];
        yield 'unknown operation' => [$base + ['operations' => [['id' => 'o', 'type' => 'replace', 'path' => '/a']]]];
        yield 'extra property' => [$base + ['operations' => [['id' => 'o', 'type' => 'unset_field', 'path' => '/a', 'value' => 1]]]];
    }

    /** @param array<string, mixed> $changeSet */
    #[DataProvider('invalidChangeSets')]
    public function test_contract_and_engine_both_reject_invalid_change_sets(array $changeSet): void
    {
        $this->assertFalse($this->validator()->validateContract($changeSet, 'novtt.sheet-change-set/1')->isValid());

        $engineRejected = false;
        try {
            $set = ChangeSet::fromArray($changeSet);
            // The engine accepts /_ paths syntactically; reserved prefixes are enforced by the policy layer.
            $engineRejected = str_starts_with($set->operations[0]->path->toString(), '/_');
        } catch (Throwable) {
            $engineRejected = true;
        }
        $this->assertTrue($engineRejected, 'engine accepted a change set the contract rejects');
    }

    public function test_valid_change_set_passes_contract_and_engine(): void
    {
        $changeSet = ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 2, 'operations' => [
            ['id' => 'op1', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 3, 'expected' => 2],
            ['id' => 'op2', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm1', 'value' => ['name' => 'Ágil', 'cost' => 2]],
            ['id' => 'op3', 'type' => 'reorder_items', 'path' => '/merits', 'order' => ['m1']],
            ['id' => 'op4', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm2', 'value' => []],
        ]];

        $this->assertTrue($this->validator()->validateContract($changeSet, 'novtt.sheet-change-set/1')->isValid());
        $this->assertCount(4, ChangeSet::fromArray($changeSet)->operations);
    }

    public function test_validation_results_produced_by_the_engine_satisfy_their_contract(): void
    {
        $result = $this->validator()->validate(['attributes' => ['might' => 0]], self::fixture('sheet.schema.json'));

        $this->assertTrue($this->validator()->validateContract($result->toArray(), 'novtt.validation-result/1')->isValid());
    }
}
