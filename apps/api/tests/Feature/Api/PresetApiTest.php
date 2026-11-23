<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\PresetVersionStatus;
use App\Models\PresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class PresetApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function package(): array
    {
        return [
            'manifest' => $this->demoFile('manifest.json'),
            'sheet_schema' => $this->demoFile('sheet.schema.json'),
            'sheet_ui' => $this->demoFile('sheet.ui.json'),
            'rules' => $this->demoFile('rules.json'),
        ];
    }

    /** @param array<string, mixed>|string $body */
    private function import(mixed $user, array|string $body, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        return $this->actingAs($user)->call('POST', '/api/v1/presets/imports', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid(),
        ], $raw);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/presets/imports', [])->assertStatus(401)->assertJsonPath('code', 'auth.unauthenticated')
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_valid_package_imports_into_personal_library_without_canonical_status(): void
    {
        $alice = $this->makeUser();
        $response = $this->import($alice, $this->package());
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        $response->assertJsonPath('status', 'engine_compatible')
            ->assertJsonPath('canonical', false)
            ->assertJsonPath('validation_report.valid', true)
            ->assertJsonPath('engine_version', '0.1.0');
        // {} vs [] preserved: the empty-object default survives the round trip.
        $this->assertIsArray($response->json('sheet_schema.properties'));
        $this->assertDatabaseHas('user_preset_library', ['user_id' => $alice->getKey(), 'preset_version_id' => $response->json('id')]);
        $this->assertDatabaseHas('audit_events', ['action' => 'preset.imported', 'subject_id' => $response->json('id')]);
    }

    public function test_syntax_errors_are_reported_as_validation_results(): void
    {
        $this->import($this->makeUser(), '{"manifest": ')->assertStatus(422)
            ->assertJsonPath('code', 'preset.invalid')
            ->assertJsonPath('validation.issues.0.rule_id', 'json.syntax');
    }

    public function test_structural_errors_report_the_path_and_nothing_is_stored(): void
    {
        $package = $this->package();
        $package['sheet_ui']['sections'][0]['elements'][0]['access'] = 'superuser';
        $package['rules']['costs'][0]['model'] = ['type' => 'eval', 'code' => 'system("id")'];

        $response = $this->import($this->makeUser(), $package)->assertStatus(422);
        $paths = array_merge(...array_column($response->json('validation.issues'), 'paths'));
        $this->assertContains('/sheet_ui/sections/0/elements/0', $paths);
        $this->assertSame(0, PresetVersion::query()->count());
    }

    public function test_engine_incompatible_packages_are_rejected_even_if_schema_valid(): void
    {
        $package = $this->package();
        $package['rules']['dependencies'][] = ['id' => 'loop', 'path' => '/attributes/wits', 'requires' => ['/skills/lore']];

        $this->import($this->makeUser(), $package)->assertStatus(422)
            ->assertJsonPath('validation.issues.0.rule_id', 'rules.dependency_cycle');
    }

    public function test_versions_are_never_overwritten_and_reimport_is_idempotent(): void
    {
        $alice = $this->makeUser();
        $first = $this->import($alice, $this->package())->assertCreated();
        $this->import($alice, $this->package())->assertOk()->assertJsonPath('id', $first->json('id'));

        $changed = $this->package();
        $changed['rules']['budgets'][0]['initial_amount'] = 99;
        $this->import($alice, $changed)->assertStatus(409)->assertJsonPath('code', 'preset.version_exists');

        $changed['manifest']['version'] = '1.0.1';
        $this->import($alice, $changed)->assertCreated();
        $this->assertSame(2, PresetVersion::query()->count());
    }

    public function test_idempotency_key_replays_and_rejects_different_payloads(): void
    {
        $alice = $this->makeUser();
        $key = (string) Str::uuid();
        $first = $this->import($alice, $this->package(), $key)->assertCreated();
        $this->import($alice, $this->package(), $key)->assertCreated()->assertJsonPath('id', $first->json('id'));

        $other = $this->package();
        $other['manifest']['version'] = '2.0.0';
        $this->import($alice, $other, $key)->assertStatus(409)->assertJsonPath('code', 'idempotency.key_reused');
    }

    public function test_oversized_packages_are_rejected(): void
    {
        config(['novtt.preset_import_max_bytes' => 1024]);
        $this->import($this->makeUser(), $this->package())->assertStatus(413)->assertJsonPath('code', 'preset.too_large');
    }

    public function test_personal_imports_stay_private_and_canonical_drafts_are_not_listed(): void
    {
        [$alice, $bob, $team] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $id = $this->import($alice, $this->package())->json('id');
        $canonical = $this->makePresetVersion($team, PresetVersionStatus::Published);
        $this->makePresetVersion($team, PresetVersionStatus::Reviewed);

        $this->actingAs($bob)->getJson("/api/v1/preset-versions/{$id}")->assertNotFound()->assertJsonPath('code', 'resource.not_found');
        $this->actingAs($bob)->getJson('/api/v1/presets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $canonical->getKey());
        $this->actingAs($alice)->getJson('/api/v1/presets?scope=personal')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->actingAs($alice)->getJson('/api/v1/presets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_team_publishes_canonical_versions_only_through_the_console(): void
    {
        $team = $this->makeUser();
        $this->artisan('novtt:preset:publish', ['directory' => self::DEMO_PRESET, '--actor' => $team->email])->assertSuccessful();

        $version = PresetVersion::query()->with('definition')->sole();
        $this->assertSame(PresetVersionStatus::Published, $version->status);
        $this->assertNull($version->definition->owner_user_id);
        $this->assertSame(0, DB::table('user_preset_library')->count());
        $this->actingAs($this->makeUser())->getJson('/api/v1/presets?scope=canonical')->assertOk()->assertJsonPath('data.0.canonical', true);
    }
}
