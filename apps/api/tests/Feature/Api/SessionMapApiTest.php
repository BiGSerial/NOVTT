<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignImage;
use App\Models\CampaignNpc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-003 NT-15: the scene map — the master places pins; players see it only when shown. */
final class SessionMapApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;

    private User $ana;

    private Campaign $campaign;

    private CampaignCharacter $lyra;

    private CampaignNpc $wolf;

    private string $sessionId;

    private string $imageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->ana = $this->makeUser('Ana');
        $this->campaign = $this->makeCampaign($this->master);
        $this->addMember($this->campaign, $this->ana);
        $this->lyra = $this->makeCharacter($this->campaign, $this->ana);
        $this->wolf = CampaignNpc::query()->create(['campaign_id' => $this->campaign->getKey(), 'name' => 'Lobo', 'preset_version_id' => $this->campaign->preset_version_id, 'sheet' => (object) [], 'created_by' => $this->master->getKey()]);
        $this->imageId = CampaignImage::query()->forceCreate(['bytes' => 'RIFF', 'campaign_id' => $this->campaign->getKey(), 'kind' => 'scene', 'mime' => 'image/webp', 'width' => 10, 'height' => 10, 'sha256' => str_repeat('a', 64), 'created_by' => $this->master->getKey()])->getKey();
        $this->sessionId = $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'S'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function map(bool $shown, int $revision = 0, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->master)->putJson("/api/v1/sessions/{$this->sessionId}/map", ['revision' => $revision, 'image_id' => $this->imageId, 'shown' => $shown, 'pins' => [
            ['kind' => 'character', 'id' => $this->lyra->getKey(), 'x' => 0.25, 'y' => 0.5],
            ['kind' => 'npc', 'id' => $this->wolf->getKey(), 'x' => 0.8, 'y' => 0.2],
        ]]);
    }

    public function test_only_the_master_places_pins_and_hidden_maps_reach_nobody(): void
    {
        $this->map(false, 0, $this->ana)->assertForbidden();
        $this->map(false)->assertOk()->assertJsonPath('revision', 1)->assertJsonCount(2, 'pins');
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonPath('session.map.shown', false)->assertJsonPath('session.map.image_id', null)->assertJsonCount(0, 'session.map.pins');
        $this->actingAs($this->ana)->get("/api/v1/images/{$this->imageId}")->assertNotFound();
        $this->map(true, 0)->assertStatus(409)->assertJsonPath('code', 'map.revision_mismatch');
    }

    public function test_a_shown_map_carries_character_pins_and_unrevealed_npcs_stay_hidden(): void
    {
        $this->map(true)->assertOk();
        $snap = $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk();
        $snap->assertJsonPath('session.map.image_id', $this->imageId)->assertJsonCount(1, 'session.map.pins')->assertJsonPath('session.map.pins.0.id', $this->lyra->getKey());
        $events = collect($this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/events?after=0")->json('data'))->pluck('type');
        self::assertContains('map.view', $events->all());
        self::assertNotContains('map.updated', $events->all(), 'the full map (with NPC pins) is master-only');
    }

    public function test_pins_must_belong_to_the_campaign(): void
    {
        $stranger = $this->makeCharacter($this->makeCampaign($this->master), $this->ana);
        $this->actingAs($this->master)->putJson("/api/v1/sessions/{$this->sessionId}/map", ['revision' => 0, 'image_id' => null, 'shown' => true, 'pins' => [['kind' => 'character', 'id' => $stranger->getKey(), 'x' => 0.1, 'y' => 0.1]]])
            ->assertStatus(409)->assertJsonPath('code', 'map.pin_invalid');
    }
}
