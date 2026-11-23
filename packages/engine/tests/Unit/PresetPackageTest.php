<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\PresetCompatibility;
use Novtt\Engine\Presets\PublicationGate;
use Novtt\Engine\Sheets\JsonPointer;
use Novtt\Engine\Tests\Support\DemoPreset;
use Novtt\Engine\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Sprint 001 (preset engine) acceptance tests 7 (rights) and 8 (valid and invalid packages). */
final class PresetPackageTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $parts */
    private static function compatibility(array $parts): ValidationResult
    {
        return (new PresetCompatibility())->check($parts['manifest'], $parts['sheet_schema'], $parts['sheet_ui'], $parts['rules'], $parts['compendium'] ?? null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function demoVariants(): iterable
    {
        yield 'pool and point-buy' => ['demo-linhagens'];
        yield 'd20 and levels' => ['demo-heroico'];
    }

    #[DataProvider('demoVariants')]
    public function test_demo_presets_are_engine_compatible_and_publishable(string $variant): void
    {
        $preset = new DemoPreset($variant);
        $this->assertSame([], self::compatibility($preset->parts)->toArray()['issues']);
        $this->assertSame([], (new PublicationGate())->check($preset->parts['manifest'], $preset->parts['compendium'])->toArray()['issues']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidCases(): iterable
    {
        $cases = DemoPreset::json(DemoPreset::ROOT . '/invalid-examples.json');
        foreach ($cases['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invalidCases')]
    public function test_documented_invalid_examples_fail_with_their_rule_id(array $case): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $parts = $preset->parts;
        foreach ($case['patch'] as $patch) {
            self::apply($parts[$patch['part']], (string) $patch['path'], $patch['value'], (string) $patch['op']);
        }
        $rules = array_map(static fn (array $i): string => $i['rule_id'], self::compatibility($parts)->toArray()['issues']);
        $this->assertContains($case['expect'], $rules, json_encode($rules));
    }

    public function test_rules_v1_packages_remain_compatible(): void
    {
        $root = __DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0';
        $parts = [];
        foreach (['manifest' => 'manifest', 'sheet_schema' => 'sheet.schema', 'sheet_ui' => 'sheet.ui', 'rules' => 'rules'] as $key => $file) {
            $parts[$key] = DemoPreset::json("{$root}/{$file}.json");
        }
        $this->assertTrue(self::compatibility($parts)->isValid());
    }

    // Test 7: rights ----------------------------------------------------------------------------

    public function test_content_without_approved_rights_is_not_published(): void
    {
        $manifest = (new DemoPreset('demo-linhagens'))->parts['manifest'];
        foreach (['draft', 'rights_review', 'retired'] as $status) {
            $manifest['license']['rights_status'] = $status;
            $this->assertSame(['rights.not_approved'], self::ids((new PublicationGate())->check($manifest)), $status);
        }
        $manifest['license']['rights_status'] = 'quarantined';
        $this->assertSame(['rights.quarantined'], self::ids((new PublicationGate())->check($manifest)));
    }

    public function test_cc_by_content_without_attribution_is_not_published(): void
    {
        $manifest = (new DemoPreset('demo-heroico'))->parts['manifest'];
        $manifest['license'] = ['identifier' => 'CC-BY-4.0', 'source_url' => 'https://creativecommons.org/licenses/by/4.0/legalcode', 'rights_status' => 'approved', 'consulted_at' => '2026-11-23'];
        $this->assertSame(['rights.attribution_missing'], self::ids((new PublicationGate())->check($manifest)));

        $manifest['license']['attribution'] = 'Attribution statement copied verbatim from the licensed source.';
        $this->assertSame([], self::ids((new PublicationGate())->check($manifest)));
    }

    public function test_a_single_quarantined_or_unverified_compendium_entry_blocks_publication(): void
    {
        $preset = new DemoPreset('demo-linhagens');
        $compendium = $preset->parts['compendium'];
        $compendium['items'][2]['source']['rights_status'] = 'quarantined';
        $compendium['items'][3]['source']['review_status'] = 'unverified';
        $compendium['items'][4]['labels']['pt-BR'] = 'Nome trocado sem novo hash';
        $result = (new PublicationGate())->check($preset->parts['manifest'], $compendium);

        $this->assertSame(['rights.quarantined', 'rights.content_hash_mismatch', 'rights.unverified_source', 'rights.content_hash_mismatch', 'rights.content_hash_mismatch'], self::ids($result));
    }

    public function test_cc_by_compendium_entries_need_a_resolvable_attribution(): void
    {
        $preset = new DemoPreset('demo-heroico');
        $compendium = $preset->parts['compendium'];
        $item = $compendium['items'][0];
        unset($item['content_hash']);
        $item['source']['license_id'] = 'CC-BY-4.0';
        $compendium['items'] = [$item];
        $this->assertSame(['rights.attribution_missing'], self::ids((new PublicationGate())->check($preset->parts['manifest'], $compendium)));
    }

    public function test_manifests_without_rights_metadata_cannot_be_published(): void
    {
        $legacy = DemoPreset::json(__DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0/manifest.json');
        $this->assertSame(['rights.manifest_without_rights'], self::ids((new PublicationGate())->check($legacy)));
        $this->assertFalse(PublicationGate::publiclyListable('published', 'quarantined'));
        $this->assertFalse(PublicationGate::publiclyListable('engine_compatible', 'approved'));
        $this->assertTrue(PublicationGate::publiclyListable('published', 'approved'));
    }

    /** @return list<string> */
    private static function ids(ValidationResult $result): array
    {
        return array_map(static fn (array $i): string => $i['rule_id'], $result->toArray()['issues']);
    }

    /** Minimal JSON Pointer add/replace ("-" appends) for the documented cases. @param array<string, mixed> $doc */
    private static function apply(array &$doc, string $path, mixed $value, string $op): void
    {
        $segments = JsonPointer::parse($path)->segments;
        $last = array_pop($segments);
        $node = &$doc;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }
        if ($op === 'add' && $last === '-') {
            $node[] = $value;
        } else {
            $node[$last] = $value;
        }
    }
}
