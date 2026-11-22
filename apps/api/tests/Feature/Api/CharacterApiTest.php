<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\CampaignRole;
use App\Domain\ChangeRequestStatus;
use App\Models\CampaignCharacter;
use App\Models\LibraryCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class CharacterApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private static function fullSheet(): array
    {
        return [
            'identity' => ['name' => 'Ana', 'concept' => 'Batedora'],
            'attributes' => ['might' => 3],
            'merits' => [['id' => 'm1', 'name' => 'Ágil', 'cost' => 2]],
            'secrets' => ['hook' => 'Deve ao conde'],
            'master' => ['notes' => 'Vai trair'],
        ];
    }

    public function test_library_is_private_and_distinct_from_campaign_instances(): void
    {
        [$alice, $bob, $master] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);

        $library = $this->actingAs($alice)->postJson('/api/v1/characters', ['name' => 'Ana'])->assertCreated()->json();
        $this->actingAs($bob)->getJson("/api/v1/characters/{$library['id']}")->assertNotFound();
        $this->actingAs($bob)->getJson('/api/v1/characters')->assertOk()->assertJsonCount(0, 'data');

        $instance = $this->actingAs($alice)->postJson("/api/v1/campaigns/{$campaign->getKey()}/characters", ['name' => 'Ana', 'library_character_id' => $library['id']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('sheet_revision', 0)->assertJsonPath('preset_version_id', $campaign->preset_version_id);
        $this->assertNotSame($library['id'], $instance->json('id'));
        $this->actingAs($alice)->postJson("/api/v1/campaigns/{$campaign->getKey()}/characters", ['name' => 'Again', 'library_character_id' => $library['id']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'character.already_in_campaign');

        // Someone else's library entry cannot be linked.
        $this->addMember($campaign, $bob);
        $this->actingAs($bob)->postJson("/api/v1/campaigns/{$campaign->getKey()}/characters", ['name' => 'Steal', 'library_character_id' => $library['id']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'character.library_unavailable');

        // Deleting the library entry keeps the campaign instance.
        $this->actingAs($alice)->deleteJson("/api/v1/characters/{$library['id']}")->assertNoContent();
        $this->assertNull(CampaignCharacter::query()->findOrFail($instance->json('id'))->library_character_id);
        $this->assertSame(0, LibraryCharacter::query()->count());
    }

    public function test_outsiders_cannot_add_characters(): void
    {
        $campaign = $this->makeCampaign($this->makeUser());
        $this->actingAs($this->makeUser())->postJson("/api/v1/campaigns/{$campaign->getKey()}/characters", ['name' => 'X'], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_sheets_are_filtered_per_field_for_players(): void
    {
        [$master, $alice, $bob] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $this->addMember($campaign, $bob);
        $character = $this->makeCharacter($campaign, $alice);
        $character->update(['sheet' => self::fullSheet() + ['undeclared' => ['x' => 'leak']]]);
        $url = "/api/v1/campaign-characters/{$character->getKey()}";

        $player = $this->actingAs($alice)->getJson($url)->assertOk();
        $this->assertSame(3, $player->json('sheet.attributes.might'));
        $this->assertNull($player->json('sheet.secrets'));
        $this->assertNull($player->json('sheet.master'));
        foreach (['conde', 'trair', 'leak'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $player->getContent());
        }

        $asMaster = $this->actingAs($master)->getJson($url)->assertOk();
        $this->assertSame('Deve ao conde', $asMaster->json('sheet.secrets.hook'));
        $this->assertStringNotContainsString('leak', (string) $asMaster->getContent());

        $this->actingAs($bob)->getJson($url)->assertNotFound();
        $this->actingAs($bob)->getJson("/api/v1/campaigns/{$campaign->getKey()}/characters")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($master)->getJson("/api/v1/campaigns/{$campaign->getKey()}/characters")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_transfer_and_custody_are_master_only_and_audited(): void
    {
        [$master, $alice, $bob] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $this->addMember($campaign, $bob);
        $outsider = $this->makeUser();
        $character = $this->makeCharacter($campaign, $alice);
        $url = "/api/v1/campaign-characters/{$character->getKey()}";

        $this->actingAs($alice)->postJson("{$url}/transfer", ['to_user_id' => $bob->getKey()])->assertNotFound();
        $this->actingAs($master)->postJson("{$url}/transfer", ['to_user_id' => $outsider->getKey()])->assertStatus(422);
        $this->actingAs($master)->postJson("{$url}/transfer", ['to_user_id' => $master->getKey()])->assertStatus(422);

        $request = $this->makeChangeRequest($character, $alice);
        $this->actingAs($master)->postJson("{$url}/transfer", ['to_user_id' => $bob->getKey()])->assertStatus(409)->assertJsonPath('code', 'character.active_proposal');
        $request->update(['status' => ChangeRequestStatus::Withdrawn]);

        $this->actingAs($master)->postJson("{$url}/transfer", ['to_user_id' => $bob->getKey()])->assertOk()->assertJsonPath('player_user_id', $bob->getKey());
        $this->actingAs($alice)->getJson($url)->assertNotFound();
        $this->actingAs($bob)->getJson($url)->assertOk();

        $this->actingAs($master)->putJson("{$url}/custody")->assertOk()->assertJsonPath('in_custody', true);
        $this->actingAs($master)->putJson("{$url}/custody")->assertOk(); // idempotent
        $this->actingAs($bob)->putJson("{$url}/custody")->assertNotFound();
        $this->actingAs($master)->deleteJson("{$url}/custody")->assertOk()->assertJsonPath('in_custody', false);

        foreach (['campaign_character.transferred', 'campaign_character.custody_taken', 'campaign_character.custody_released'] as $action) {
            $this->assertDatabaseHas('audit_events', ['action' => $action, 'subject_id' => $character->getKey()]);
        }
    }

    public function test_archiving_preserves_the_character_and_withdraws_active_proposals(): void
    {
        [$master, $alice] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);
        $character = $this->makeCharacter($campaign, $alice);
        $request = $this->makeChangeRequest($character, $alice);
        $url = "/api/v1/campaign-characters/{$character->getKey()}";

        $this->actingAs($alice)->postJson("{$url}/archive")->assertNotFound();
        $this->actingAs($master)->postJson("{$url}/archive")->assertOk()->assertJsonPath('status', 'archived');
        $this->assertSame(ChangeRequestStatus::Withdrawn, $request->refresh()->status);
        $this->actingAs($master)->postJson("{$url}/archive")->assertStatus(409)->assertJsonPath('code', 'character.archived');
        $this->actingAs($alice)->getJson($url)->assertOk()->assertJsonPath('status', 'archived');
    }

    public function test_campaign_npcs_are_sanitized_master_only_copies(): void
    {
        [$master, $alice] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $alice);

        $npc = $this->actingAs($master)->postJson('/api/v1/npcs', [
            'name' => 'Conde', 'preset_version_id' => $campaign->preset_version_id,
            'sheet' => ['identity' => ['name' => 'Conde'], 'master' => ['notes' => 'Vampiro antigo']],
            'private_notes' => 'Plano secreto do mestre',
        ])->assertCreated()->json();
        $this->actingAs($master)->postJson('/api/v1/npcs', ['name' => 'Bad', 'preset_version_id' => $campaign->preset_version_id, 'sheet' => ['attributes' => ['might' => 99]]])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'schema.maximum');
        $this->actingAs($master)->postJson('/api/v1/npcs', ['name' => 'Bad', 'sheet' => ['a' => 1]])->assertStatus(422);
        $this->actingAs($alice)->getJson('/api/v1/npcs')->assertOk()->assertJsonCount(0, 'data');

        $copy = $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/npcs", ['source_npc_id' => $npc['id']])->assertCreated();
        $copy->assertJsonPath('name', 'Conde')->assertJsonPath('sheet.master.notes', 'Vampiro antigo');
        $this->assertStringNotContainsString('Plano secreto', (string) $copy->getContent());
        $this->assertDatabaseMissing('campaign_npcs', ['id' => $copy->json('id'), 'sheet' => json_encode(['private_notes' => 'Plano secreto do mestre'])]);

        $this->actingAs($alice)->getJson("/api/v1/campaigns/{$campaign->getKey()}/npcs")->assertNotFound();
        $this->actingAs($alice)->postJson("/api/v1/campaigns/{$campaign->getKey()}/npcs", ['name' => 'X'])->assertNotFound();
        $this->actingAs($master)->getJson("/api/v1/campaigns/{$campaign->getKey()}/npcs")->assertOk()->assertJsonCount(1, 'data');

        // An NPC from another preset version cannot be copied into this campaign.
        $other = $this->makePresetVersion($master, version: '2.0.0');
        $foreign = $this->actingAs($master)->postJson('/api/v1/npcs', ['name' => 'Outro', 'preset_version_id' => $other->getKey()])->json('id');
        $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/npcs", ['source_npc_id' => $foreign])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'npc.preset_mismatch');
        $this->addMember($campaign, $this->makeUser(), CampaignRole::Master);
    }
}
