<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\DemoMode;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    /** A first-party origin, so Sanctum starts the session (ADR 0009). */
    private const ORIGIN = ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];

    private function enable(): void
    {
        config(['novtt.demo_mode' => true]);
    }

    public function test_demo_routes_do_not_exist_by_default(): void
    {
        $this->getJson('/api/dev/demo')->assertNotFound()->assertJsonPath('code', 'resource.not_found');
        $this->postJson('/api/dev/demo/session', ['role' => 'master'], self::ORIGIN)->assertNotFound();
        $this->artisan('novtt:demo:seed')->assertFailed();
        $this->assertFalse(User::query()->where('email', DemoMode::MASTER_EMAIL)->exists());
    }

    public function test_flag_is_ignored_in_production(): void
    {
        $this->enable();
        $this->app['env'] = 'production';
        $this->assertFalse(DemoMode::enabled());
        $this->getJson('/api/dev/demo')->assertNotFound();
        $this->postJson('/api/dev/demo/session', ['role' => 'master'])->assertNotFound();
        // A first-party origin in production also has to pass CSRF before reaching the guard.
        $this->postJson('/api/dev/demo/session', ['role' => 'master'], self::ORIGIN)->assertStatus(419);
        $this->artisan('novtt:demo:seed')->assertFailed();
        $this->assertFalse(User::query()->where('email', DemoMode::MASTER_EMAIL)->exists());
    }

    public function test_seed_is_idempotent_and_uses_the_real_importer(): void
    {
        $this->enable();
        $this->artisan('novtt:demo:seed')->assertSuccessful();
        $counts = fn (): array => [User::query()->count(), PresetVersion::query()->count(), Campaign::query()->count(), CampaignCharacter::query()->count()];
        $first = $counts();
        $this->artisan('novtt:demo:seed')->assertSuccessful();
        $this->assertSame($first, $counts());

        $this->assertSame(2, User::query()->whereIn('email', [DemoMode::MASTER_EMAIL, DemoMode::PLAYER_EMAIL])->count());
        $campaign = Campaign::query()->where('name', DemoMode::CAMPAIGN_NAME)->sole();
        $character = CampaignCharacter::query()->where('campaign_id', $campaign->getKey())->sole();
        $this->assertSame(DemoMode::CHARACTER_NAME, $character->name);
        $this->assertSame($campaign->preset_version_id, $character->preset_version_id);
        $this->assertSame('published', PresetVersion::query()->findOrFail($campaign->preset_version_id)->status->value);
    }

    public function test_login_requires_seed_valid_role_and_first_party_origin(): void
    {
        $this->enable();
        $this->postJson('/api/dev/demo/session', ['role' => 'player'], self::ORIGIN)->assertStatus(409)->assertJsonPath('code', 'demo.not_seeded');
        $this->artisan('novtt:demo:seed')->assertSuccessful();
        $this->postJson('/api/dev/demo/session', ['role' => 'admin'], self::ORIGIN)->assertStatus(422);
        $this->postJson('/api/dev/demo/session', ['role' => 'player', 'user_id' => 'x'], self::ORIGIN)->assertStatus(422);
        $this->postJson('/api/dev/demo/session', ['role' => 'player'])->assertStatus(400)->assertJsonPath('code', 'demo.stateful_origin_required');
    }

    public function test_demo_session_goes_through_real_policies(): void
    {
        $this->enable();
        $this->artisan('novtt:demo:seed')->assertSuccessful();
        $state = $this->getJson('/api/dev/demo')->assertOk()->assertJsonPath('seeded', true)->assertJsonPath('user', null)->json();
        $characterId = $state['campaign_character_id'];

        $this->postJson('/api/dev/demo/session', ['role' => 'player'], self::ORIGIN)->assertOk()->assertJsonPath('user.role', 'player');
        $this->getJson("/api/v1/campaign-characters/{$characterId}", self::ORIGIN)->assertOk()->assertJsonPath('name', DemoMode::CHARACTER_NAME);
        // Master-only action stays denied for the demo player (uniform 404).
        $this->postJson("/api/v1/campaign-characters/{$characterId}/approve", [], self::ORIGIN + ['Idempotency-Key' => 'demo-k1'])->assertNotFound();

        $this->deleteJson('/api/dev/demo/session', [], self::ORIGIN)->assertNoContent();
        $this->postJson('/api/dev/demo/session', ['role' => 'master'], self::ORIGIN)->assertOk()->assertJsonPath('user.role', 'master');
        $this->getJson("/api/v1/campaigns/{$state['campaign_id']}/characters", self::ORIGIN)->assertOk();
    }
}
