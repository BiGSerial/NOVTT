<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Problems\ApiProblem;
use App\Models\CampaignCharacter;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\Support\DrivesProposals;
use Tests\TestCase;

/** Preset engine Sprint 001 tests 4 (levels, pinned revisions) and 7 (rights) through the API. */
final class PresetProgressionRightsApiTest extends TestCase
{
    use BuildsDomain;
    use DrivesProposals;
    use RefreshDatabase;

    private User $team;
    private User $master;
    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = $this->makeUser('Equipe');
        $this->master = $this->makeUser('Mestre');
        $this->player = $this->makeUser('Ana');
    }

    private function heroicoCharacter(PresetVersion $version): CampaignCharacter
    {
        $campaign = $this->makeCampaign($this->master, $version);
        $this->addMember($campaign, $this->player);
        $character = $this->makeCharacter($campaign, $this->player);
        $this->applyAsProposal($this->player, $this->master, $character, self::heroicoCreation());
        $this->approveCharacter($this->master, $character)->assertOk();

        return $character;
    }

    // Test 4 -------------------------------------------------------------------------------------

    public function test_xp_unlocks_the_next_level_and_a_newer_revision_does_not_touch_pinned_characters(): void
    {
        $v1 = $this->publishDemoPreset($this->team, 'demo-heroico');
        $character = $this->heroicoCharacter($v1);
        $this->grantBudget($this->master, $character, 'xp', 300)->assertCreated();
        $this->applyAsProposal($this->player, $this->master, $character, [self::op('lv', '/progress/level', 2)]);
        $this->assertSame(2, $character->refresh()->sheet['progress']['level']);
        $this->actingAs($this->player)->getJson("/api/v1/campaign-characters/{$character->getKey()}")->assertOk()
            ->assertJsonPath('sheet.progress.proficiency', 2)
            ->assertJsonPath('sheet.progress.max_hp', 14); // level 2 x (6 + mod(13) = 1)

        // A later revision raises the level-2 threshold to 500 XP.
        $v11 = $this->publishDemoPreset($this->team, 'demo-heroico', static function (array $package): array {
            $package['manifest']['version'] = '1.1.0';
            $package['rules']['progression']['tracks'][0]['thresholds'][1] = 500;

            return $package;
        });
        $this->assertNotSame($v1->getKey(), $v11->getKey());
        $this->assertSame($v1->getKey(), $character->refresh()->preset_version_id, 'no implicit migration');
        $this->assertSame(2, $character->sheet['progress']['level']);

        $newer = $this->heroicoCharacter($v11);
        $this->grantBudget($this->master, $newer, 'xp', 300)->assertCreated();
        $this->saveDraft($this->player, $newer, [self::op('lv', '/progress/level', 2)])->assertOk();
        $this->submitDraft($this->player, $newer)->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'progression.level_not_earned');
    }

    public function test_levels_cannot_be_skipped_or_taken_without_experience(): void
    {
        $character = $this->heroicoCharacter($this->publishDemoPreset($this->team, 'demo-heroico'));
        $this->saveDraft($this->player, $character, [self::op('lv', '/progress/level', 2)])->assertOk();
        $this->submitDraft($this->player, $character)->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'progression.level_not_earned');

        $this->grantBudget($this->master, $character, 'milestones', 2)->assertCreated();
        $this->saveDraft($this->player, $character, [self::op('lv', '/progress/level', 3)])->assertOk();
        $this->submitDraft($this->player, $character)->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'progression.level_skip');
    }

    // Test 7 -------------------------------------------------------------------------------------

    /** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function blockedPackages(): iterable
    {
        yield 'rights still in draft' => [static function (array $p): array {
            $p['manifest']['license']['rights_status'] = 'draft';
            $p['manifest']['publication']['public'] = false;

            return $p;
        }, 'rights.not_approved'];
        yield 'quarantined content' => [static function (array $p): array {
            $p['manifest']['license']['rights_status'] = 'quarantined';
            $p['manifest']['publication']['public'] = false;

            return $p;
        }, 'rights.quarantined'];
        yield 'CC-BY without attribution' => [static function (array $p): array {
            $p['manifest']['license']['identifier'] = 'CC-BY-4.0';
            unset($p['manifest']['license']['requires_attribution'], $p['manifest']['license']['attribution']);

            return $p;
        }, 'rights.attribution_missing'];
        yield 'one unapproved compendium entry' => [static function (array $p): array {
            $p['compendium']['items'][0]['source']['rights_status'] = 'rights_review';
            unset($p['compendium']['items'][0]['content_hash']);

            return $p;
        }, 'rights.not_approved'];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockedPackages')]
    public function test_canonical_publication_without_rights_is_refused_and_nothing_is_stored(callable $mutate, string $rule): void
    {
        try {
            $this->publishDemoPreset($this->team, 'demo-linhagens', $mutate);
            $this->fail('expected preset.rights_blocked');
        } catch (ApiProblem $problem) {
            $this->assertSame('preset.rights_blocked', $problem->problemCode);
            $this->assertContains($rule, array_column($problem->extra['validation']['issues'], 'rule_id'));
        }
        $this->assertSame(0, PresetVersion::query()->count());
    }

    public function test_quarantine_removes_a_version_from_the_catalogue_but_pinned_campaigns_keep_working(): void
    {
        $version = $this->publishDemoPreset($this->team, 'demo-linhagens');
        $campaign = $this->makeCampaign($this->master, $version);
        $character = $this->makeCharacter($campaign, $this->master);
        $listed = $this->actingAs($this->player)->getJson('/api/v1/presets?scope=canonical')->assertOk()
            ->assertJsonPath('data.0.id', $version->getKey())
            ->assertJsonPath('data.0.rights_status', 'approved')
            ->assertJsonPath('data.0.rules_revision', 'original-2026')
            ->assertJsonPath('data.0.license.identifier', 'NOVTT-original')
            ->assertJsonPath('data.0.source_authenticity', 'original')
            ->assertJsonPath('data.0.coverage', 'playable_core');
        $this->assertSame(['lineage', 'gift'], array_column($listed->json('data.0.taxonomy'), 'kind'));

        $this->artisan('novtt:preset:rights', ['preset_version_id' => $version->getKey(), 'status' => 'quarantined', '--actor' => $this->team->email, '--reason' => 'Revisão de direitos pendente'])->assertSuccessful();

        $this->actingAs($this->player)->getJson('/api/v1/presets?scope=canonical')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->player)->getJson("/api/v1/preset-versions/{$version->getKey()}")->assertNotFound();
        $this->actingAs($this->master)->getJson("/api/v1/campaign-characters/{$character->getKey()}")->assertOk();
        $this->actingAs($this->master)->postJson('/api/v1/campaigns', ['name' => 'Nova', 'preset_version_id' => $version->getKey()], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('code', 'campaign.preset_unavailable');
        $audit = json_decode((string) DB::table('audit_events')->where('action', 'preset.rights_changed')->value('metadata'), true);
        $this->assertEquals(['from' => 'approved', 'to' => 'quarantined', 'reason' => 'Revisão de direitos pendente'], $audit);
        $this->assertSame('quarantined', $version->refresh()->rights_status);
    }

    public function test_rights_command_rejects_unknown_states_and_requires_a_reason(): void
    {
        $version = $this->publishDemoPreset($this->team, 'demo-heroico');
        $this->artisan('novtt:preset:rights', ['preset_version_id' => $version->getKey(), 'status' => 'free', '--actor' => $this->team->email, '--reason' => 'x'])->assertFailed();
        $this->artisan('novtt:preset:rights', ['preset_version_id' => $version->getKey(), 'status' => 'retired', '--actor' => $this->team->email])->assertFailed();
        $this->assertSame('approved', $version->refresh()->rights_status);
    }

    public function test_manifest_v1_packages_still_import_into_a_personal_library_without_rights(): void
    {
        $package = [
            'manifest' => [
                'contract' => 'novtt.preset-manifest/1', 'family_id' => 'legado', 'variant_id' => 'legado', 'version' => '1.0.0', 'display_name' => 'Legado',
                'license' => 'MIT', 'provenance' => 'Teste de compatibilidade', 'engine' => ['min_version' => '0.1.0'],
                'files' => ['sheet_schema' => 'sheet.schema.json', 'sheet_ui' => 'sheet.ui.json', 'rules' => 'rules.json'],
            ],
            'sheet_schema' => $this->demoFile('sheet.schema.json'),
            'sheet_ui' => $this->demoFile('sheet.ui.json'),
            'rules' => $this->demoFile('rules.json'),
        ];
        $response = $this->actingAs($this->player)->call('POST', '/api/v1/presets/imports', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ], json_encode($package, JSON_THROW_ON_ERROR));
        $this->assertSame(201, $response->status(), (string) $response->getContent());
        $response->assertJsonPath('canonical', false)->assertJsonPath('rights_status', 'draft')->assertJsonPath('rules_revision', null);
        $this->actingAs($this->makeUser())->getJson('/api/v1/presets')->assertOk()->assertJsonCount(0, 'data');
    }
}
