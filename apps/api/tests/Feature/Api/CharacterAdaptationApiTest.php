<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\CampaignCharacterStatus;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CharacterDraft;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/**
 * Sheet versions: the master moves a campaign to a newer version of its preset; each character
 * is adapted by its owner or the master (what is dropped is reported); a player can bring a
 * character from another campaign.
 */
final class CharacterAdaptationApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;

    private User $ana;

    private Campaign $campaign;

    private PresetVersion $v1;

    private PresetVersion $v2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->ana = $this->makeUser('Ana');
        $this->v1 = $this->makePresetVersion($this->master);
        // 1.1.0 drops `skills/lore` and narrows `attributes/might` to at most 3.
        $schema = $this->v1->sheet_schema;
        unset($schema['properties']['skills']['properties']['lore']);
        $schema['properties']['attributes']['properties']['might']['maximum'] = 3;
        $ui = $this->v1->sheet_ui;
        foreach ($ui['sections'] as $s => $section) {
            $ui['sections'][$s]['elements'] = array_values(array_filter($section['elements'], static fn (array $e): bool => ($e['path'] ?? '') !== '/skills/lore'));
        }
        $v1 = $this->v1;
        $this->v2 = PresetVersion::query()->create([
            'definition_id' => $v1->definition_id, 'version' => '1.1.0', 'status' => $v1->status, 'content_hash' => hash('sha256', 'v2'),
            'manifest' => $v1->manifest, 'rules' => $v1->rules, 'validation_report' => $v1->validation_report, 'engine_version' => $v1->engine_version,
            'imported_by' => $v1->imported_by, 'published_at' => $v1->published_at, 'rights_status' => $v1->rights_status,
            'sheet_schema' => $schema, 'sheet_ui' => $ui,
        ]);
        $this->campaign = $this->makeCampaign($this->master, $this->v1);
        $this->addMember($this->campaign, $this->ana);
    }

    private function hero(CampaignCharacterStatus $status = CampaignCharacterStatus::Approved): CampaignCharacter
    {
        $c = $this->makeCharacter($this->campaign, $this->ana);
        $c->update(['status' => $status, 'sheet_revision' => 1, 'sheet' => ['identity' => ['name' => 'Iara'], 'attributes' => ['might' => 4, 'agility' => 2], 'skills' => ['climb' => 1, 'lore' => 2]]]);

        return $c->refresh();
    }

    private function upgrade(int $revision = 1): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/preset-upgrade", ['preset_version_id' => $this->v2->getKey(), 'expected_revision' => $revision]);
    }

    public function test_the_master_upgrades_the_campaign_and_characters_keep_their_version_until_adapted(): void
    {
        $hero = $this->hero();
        $this->actingAs($this->master)->getJson("/api/v1/campaigns/{$this->campaign->getKey()}/preset-upgrades")->assertOk()->assertJsonPath('data.0.version', '1.1.0');
        $this->actingAs($this->ana)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/preset-upgrade", ['preset_version_id' => $this->v2->getKey(), 'expected_revision' => 1])->assertForbidden();
        $this->upgrade()->assertOk()->assertJsonPath('preset_version_id', $this->v2->getKey());
        $this->upgrade(1)->assertStatus(409);

        self::assertSame($this->v1->getKey(), $hero->refresh()->preset_version_id, 'nothing rewritten behind anyone\'s back');
    }

    public function test_another_preset_is_not_an_upgrade(): void
    {
        $other = $this->makePresetVersion($this->master, version: '2.0.0');
        $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/preset-upgrade", ['preset_version_id' => $other->getKey(), 'expected_revision' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'campaign.preset_upgrade_unavailable');
    }

    public function test_the_owner_sees_what_adapting_drops_then_adapts_and_the_master_approves_again(): void
    {
        $hero = $this->hero();
        CharacterDraft::query()->create(['campaign_character_id' => $hero->getKey(), 'author_user_id' => $this->ana->getKey(), 'base_sheet_revision' => 1, 'draft_version' => 1, 'change_set' => [
            'contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 1,
            'operations' => [['id' => 'a', 'type' => 'set_field', 'path' => '/skills/lore', 'value' => 3], ['id' => 'b', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 2]],
        ]]);
        $this->upgrade()->assertOk();

        $preview = $this->actingAs($this->ana)->getJson("/api/v1/campaign-characters/{$hero->getKey()}/adaptation")->assertOk();
        $preview->assertJsonPath('needed', true)->assertJsonPath('from_version', '1.0.0')->assertJsonPath('to_version', '1.1.0')
            ->assertJsonPath('needs_approval', true)->assertJsonPath('draft_operations_dropped', 1);
        self::assertEqualsCanonicalizing(['/attributes/might' => 'out_of_range', '/skills/lore' => 'field_removed'], collect($preview->json('dropped'))->pluck('reason', 'path')->all());

        $this->actingAs($this->makeUser('Outro'))->getJson("/api/v1/campaign-characters/{$hero->getKey()}/adaptation")->assertNotFound();
        $this->actingAs($this->ana)->postJson("/api/v1/campaign-characters/{$hero->getKey()}/adaptation", ['expected_sheet_revision' => 0])->assertStatus(409);
        $done = $this->actingAs($this->ana)->postJson("/api/v1/campaign-characters/{$hero->getKey()}/adaptation", ['expected_sheet_revision' => 1])->assertOk();
        $done->assertJsonPath('character.preset_version_id', $this->v2->getKey())->assertJsonPath('character.status', 'pending_approval')->assertJsonPath('character.sheet_revision', 2);

        $hero->refresh();
        self::assertSame(['climb' => 1], $hero->sheet['skills']);
        self::assertArrayNotHasKey('might', $hero->sheet['attributes']);
        $draft = CharacterDraft::query()->where('campaign_character_id', $hero->getKey())->sole();
        self::assertSame(['/skills/climb'], array_column($draft->change_set['operations'], 'path'));
        self::assertSame(2, $draft->base_sheet_revision);
        $this->actingAs($this->ana)->postJson("/api/v1/campaign-characters/{$hero->getKey()}/adaptation", ['expected_sheet_revision' => 2])->assertStatus(409)->assertJsonPath('code', 'character.already_current');
    }

    public function test_a_player_brings_a_character_from_another_campaign_of_the_same_game_with_its_sheet(): void
    {
        $hero = $this->hero();
        $other = $this->makeCampaign($this->master, $this->v2);
        $this->addMember($other, $this->ana);
        $created = $this->actingAs($this->ana)->postJson("/api/v1/campaigns/{$other->getKey()}/characters", ['name' => 'Iara', 'from_campaign_character_id' => $hero->getKey()], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $copy = CampaignCharacter::query()->findOrFail($created->json('id'));
        self::assertSame(['climb' => 1], $copy->sheet['skills'], 'adapted to the target version');
        self::assertSame('draft', $copy->status->value);

        $foreign = $this->makeCampaign($this->master, $this->makePresetVersion($this->master, version: '9.0.0'));
        $this->addMember($foreign, $this->ana);
        $fresh = $this->actingAs($this->ana)->postJson("/api/v1/campaigns/{$foreign->getKey()}/characters", ['name' => 'Iara', 'from_campaign_character_id' => $hero->getKey()], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        self::assertEmpty((array) CampaignCharacter::query()->findOrFail($fresh->json('id'))->sheet, 'another game: only the name comes along');

        $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$other->getKey()}/characters", ['name' => 'X', 'from_campaign_character_id' => $hero->getKey()], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);
    }
}
