<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Unit;

use Novtt\Engine\Presets\PresetCompatibility;
use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Sheets\FieldAccess;
use Novtt\Engine\Sheets\JsonPointer;
use PHPUnit\Framework\TestCase;

final class PresetCompatibilityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../presets/fixtures/demo-basic/1.0.0';

    /** @return array<string, mixed> */
    private static function load(string $file): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE . '/' . $file), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} */
    private static function package(): array
    {
        return [self::load('manifest.json'), self::load('sheet.schema.json'), self::load('sheet.ui.json'), self::load('rules.json')];
    }

    /** @return list<string> */
    private static function rules(array $manifest, array $schema, array $ui, array $rules): array
    {
        return array_map(static fn ($i): string => $i->ruleId, (new PresetCompatibility())->check($manifest, $schema, $ui, $rules)->issues);
    }

    public function test_demo_fixture_is_engine_compatible(): void
    {
        [$m, $s, $u, $r] = self::package();
        $this->assertSame([], self::rules($m, $s, $u, $r));
    }

    public function test_unsupported_schema_keywords_and_remote_refs_are_rejected(): void
    {
        [$m, $s, $u, $r] = self::package();
        $s['properties']['identity']['properties']['name']['$ref'] = 'https://evil.example/schema.json';
        $s['properties']['attributes']['if'] = ['type' => 'object'];

        $found = self::rules($m, $s, $u, $r);
        $this->assertSame(['UNSUPPORTED_CAPABILITY', 'UNSUPPORTED_CAPABILITY'], $found);
    }

    public function test_reserved_engine_properties_are_rejected(): void
    {
        [$m, $s, $u, $r] = self::package();
        $s['properties']['_ledger'] = ['type' => 'object'];
        $this->assertContains('schema.reserved_property', self::rules($m, $s, $u, $r));
    }

    public function test_ui_paths_must_exist_with_the_right_shape(): void
    {
        [$m, $s, $u, $r] = self::package();
        $u['sections'][0]['elements'][] = ['kind' => 'field', 'path' => '/identity/missing', 'label' => 'X', 'widget' => 'text'];
        $u['sections'][0]['elements'][] = ['kind' => 'field', 'path' => '/attributes', 'label' => 'X', 'widget' => 'text'];
        $u['sections'][1]['elements'][] = ['kind' => 'field', 'path' => '/identity/concept', 'label' => 'Dup', 'widget' => 'dot_rating'];
        $u['sections'][3]['elements'][0]['item_fields'][] = ['field' => 'id', 'label' => 'Id', 'widget' => 'text'];

        $found = self::rules($m, $s, $u, $r);
        $this->assertContains('ui.path_not_scalar_field', $found);
        $this->assertContains('ui.duplicate_path', $found);
        $this->assertContains('ui.dot_rating_requires_integer', $found);
        $this->assertContains('ui.item_field_unknown', $found);
        $this->assertContains('ui.nested_elements', $found);
    }

    public function test_rules_must_reference_declared_budgets_and_integer_targets(): void
    {
        [$m, $s, $u, $r] = self::package();
        $r['costs'][] = ['id' => 'bad_budget', 'phase' => 'creation', 'budget' => 'mana', 'targets' => ['/attributes/*'], 'model' => ['type' => 'fixed_per_dot', 'amount' => 1]];
        $r['costs'][] = ['id' => 'bad_target', 'phase' => 'creation', 'budget' => 'xp', 'targets' => ['/identity/name'], 'model' => ['type' => 'fixed_per_dot', 'amount' => 1]];
        $r['phase_limits'][] = ['id' => 'ghost', 'phase' => 'creation', 'targets' => ['/powers/*'], 'max' => 1];

        $found = self::rules($m, $s, $u, $r);
        $this->assertContains('rules.unknown_budget', $found);
        $this->assertContains('rules.target_not_integer', $found);
        $this->assertContains('rules.unknown_target', $found);
    }

    public function test_dependency_cycles_are_rejected(): void
    {
        [$m, $s, $u, $r] = self::package();
        $r['dependencies'][] = ['id' => 'wits_needs_lore', 'path' => '/attributes/wits', 'requires' => ['/skills/lore']];

        $this->assertContains('rules.dependency_cycle', self::rules($m, $s, $u, $r));
    }

    public function test_newer_engine_requirement_is_rejected(): void
    {
        [$m, $s, $u, $r] = self::package();
        $m['engine']['min_version'] = '9.0.0';
        $this->assertSame(['engine.version_unsupported'], self::rules($m, $s, $u, $r));
    }

    public function test_layout_resolves_effective_access(): void
    {
        $layout = SheetLayout::fromArray(self::load('sheet.ui.json'));

        $this->assertSame(FieldAccess::Editable, $layout->accessOf(JsonPointer::parse('/identity/name')));
        $this->assertSame(FieldAccess::ApprovalRequired, $layout->accessOf(JsonPointer::parse('/attributes/might')), 'inherits the section access');
        $this->assertSame(FieldAccess::Hidden, $layout->accessOf(JsonPointer::parse('/secrets/hook')));
        $this->assertSame(FieldAccess::MasterOnly, $layout->accessOf(JsonPointer::parse('/master/notes')));
        $this->assertSame(FieldAccess::MasterOnly, $layout->accessOf(JsonPointer::parse('/not/declared')), 'undeclared paths are master only');
        $this->assertSame(FieldAccess::ApprovalRequired, $layout->itemFieldAccess(JsonPointer::parse('/merits'), 'cost'));
        $this->assertSame('attributes', $layout->element(JsonPointer::parse('/attributes/wits'))['section']);
    }
}
