<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\CampaignRole;
use App\Domain\PresetVersionStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class CampaignApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** @param array<string, mixed> $body */
    private function create(User $user, array $body, ?string $key = null): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/campaigns', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_master_creates_a_campaign_pinned_to_a_published_version(): void
    {
        $team = $this->makeUser();
        $master = $this->makeUser();
        $version = $this->makePresetVersion($team);

        $response = $this->create($master, ['name' => 'Mesa de sábado', 'preset_version_id' => $version->getKey()])->assertCreated();
        $response->assertJsonPath('role', 'master')->assertJsonPath('revision', 1)->assertJsonPath('preset_version_id', $version->getKey());

        $id = $response->json('id');
        $this->assertDatabaseHas('campaign_members', ['campaign_id' => $id, 'user_id' => $master->getKey(), 'role' => 'master']);
        $this->assertDatabaseHas('audit_events', ['action' => 'campaign.created', 'subject_id' => $id]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'campaign.created', 'aggregate_id' => $id]);
    }

    public function test_unavailable_versions_get_the_same_answer_whether_missing_private_or_unpinnable(): void
    {
        [$alice, $bob] = [$this->makeUser(), $this->makeUser()];
        $private = $this->makePresetVersion($alice, PresetVersionStatus::EngineCompatible, $alice);
        $draft = $this->makePresetVersion($alice, PresetVersionStatus::Reviewed);

        foreach ([(string) Str::uuid(), $private->getKey(), $draft->getKey()] as $id) {
            $this->create($bob, ['name' => 'X', 'preset_version_id' => $id])->assertStatus(422)->assertJsonPath('code', 'campaign.preset_unavailable');
        }
        $this->create($alice, ['name' => 'Own import', 'preset_version_id' => $private->getKey()])->assertCreated();
    }

    public function test_unknown_fields_are_rejected(): void
    {
        $version = $this->makePresetVersion($this->makeUser());
        $this->create($this->makeUser(), ['name' => 'X', 'preset_version_id' => $version->getKey(), 'owner_user_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'request.unknown_field');
    }

    public function test_create_is_idempotent_per_actor_and_key(): void
    {
        $master = $this->makeUser();
        $version = $this->makePresetVersion($master);
        $key = (string) Str::uuid();
        $body = ['name' => 'Mesa', 'preset_version_id' => $version->getKey()];

        $first = $this->create($master, $body, $key)->assertCreated();
        $this->create($master, $body, $key)->assertCreated()->assertJsonPath('id', $first->json('id'));
        $this->create($master, ['name' => 'Outra'] + $body, $key)->assertStatus(409)->assertJsonPath('code', 'idempotency.key_reused');
        $this->create($this->makeUser(), $body, $key)->assertCreated(); // keys are scoped per actor
        $this->assertSame(2, Campaign::query()->count());

        $this->actingAs($master)->postJson('/api/v1/campaigns', $body)->assertStatus(400)->assertJsonPath('code', 'idempotency.key_required');
    }

    public function test_only_members_see_a_campaign_and_only_masters_change_it(): void
    {
        [$master, $player, $outsider] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);
        $url = "/api/v1/campaigns/{$campaign->getKey()}";

        $this->actingAs($player)->getJson($url)->assertOk()->assertJsonPath('role', 'player');
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($player)->patchJson($url, ['expected_revision' => 1, 'name' => 'Hack'])->assertNotFound();
        $this->actingAs($outsider)->postJson("{$url}/archive")->assertNotFound();

        $this->actingAs($master)->patchJson($url, ['expected_revision' => 1, 'name' => 'Nova'])->assertOk()->assertJsonPath('revision', 2);
        $this->actingAs($master)->patchJson($url, ['expected_revision' => 1, 'name' => 'Stale'])->assertStatus(409)
            ->assertJsonPath('code', 'campaign.revision_conflict')->assertJsonPath('current_revision', 2);
        $this->actingAs($master)->patchJson($url, ['expected_revision' => 2, 'preset_version_id' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'request.unknown_field');
    }

    public function test_list_shows_only_active_memberships(): void
    {
        [$master, $other] = [$this->makeUser(), $this->makeUser()];
        $mine = $this->makeCampaign($master);
        $this->makeCampaign($other);

        $this->actingAs($master)->getJson('/api/v1/campaigns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->getKey());
    }

    public function test_archiving_preserves_history_and_freezes_the_campaign(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $url = "/api/v1/campaigns/{$campaign->getKey()}";

        $this->actingAs($master)->postJson("{$url}/archive")->assertOk()->assertJsonPath('status', 'archived');
        $this->actingAs($master)->postJson("{$url}/archive")->assertStatus(409)->assertJsonPath('code', 'campaign.archived');
        $this->actingAs($master)->patchJson($url, ['expected_revision' => 2, 'name' => 'X'])->assertStatus(409)->assertJsonPath('code', 'campaign.archived');
        $this->actingAs($master)->getJson($url)->assertOk();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey()]);
    }

    public function test_pinned_version_does_not_change_existing_characters(): void
    {
        $master = $this->makeUser();
        $v1 = $this->makePresetVersion($master);
        $campaign = $this->makeCampaign($master, $v1);
        $character = $this->makeCharacter($campaign, $master);

        // A newer version exists; nothing about the campaign or its characters moves implicitly.
        $this->makePresetVersion($master, PresetVersionStatus::Published, null, '1.1.0');

        $this->assertSame($v1->getKey(), Campaign::query()->findOrFail($campaign->getKey())->preset_version_id);
        $this->assertSame($v1->getKey(), CampaignCharacter::query()->findOrFail($character->getKey())->preset_version_id);
        $this->addMember($campaign, $this->makeUser(), CampaignRole::Master);
    }

    public function test_the_list_tells_who_masters_how_many_play_and_the_latest_session(): void
    {
        $master = $this->makeUser('Mestra Lia');
        $ana = $this->makeUser('Ana');
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $ana);
        $quiet = $this->makeCampaign($master);

        $this->actingAs($ana)->getJson('/api/v1/campaigns')->assertOk()
            ->assertJsonPath('data.0.masters.0.name', 'Mestra Lia')
            ->assertJsonPath('data.0.players_count', 1)
            ->assertJsonPath('data.0.last_session', null)
            ->assertJsonMissingPath('data.0.masters.0.email');

        $first = $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/sessions", ['title' => 'Primeira'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated()->json('id');
        $this->actingAs($master)->postJson("/api/v1/sessions/{$first}/end")->assertOk();
        $this->travel(1)->hours();
        $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/sessions", ['title' => 'Segunda'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();

        $list = collect($this->actingAs($master)->getJson('/api/v1/campaigns')->assertOk()->json('data'))->keyBy('id');
        self::assertSame('Segunda', $list[$campaign->getKey()]['last_session']['title']);
        self::assertSame('active', $list[$campaign->getKey()]['last_session']['status']);
        self::assertNull($list[$quiet->getKey()]['last_session']);
    }
}
