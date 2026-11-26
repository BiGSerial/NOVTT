<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\LiveState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-003 NT-13/NT-14: places (who is near whom) and item offers between nearby characters. */
final class PartyApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;

    private User $ana;

    private User $bruno;

    private Campaign $campaign;

    private CampaignCharacter $lyra;

    private CampaignCharacter $bram;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->ana = $this->makeUser('Ana');
        $this->bruno = $this->makeUser('Bruno');
        $this->campaign = $this->makeCampaign($this->master);
        $this->addMember($this->campaign, $this->ana);
        $this->addMember($this->campaign, $this->bruno);
        $this->lyra = $this->makeCharacter($this->campaign, $this->ana);
        $this->bram = $this->makeCharacter($this->campaign, $this->bruno);
        LiveState::query()->create(['campaign_id' => $this->campaign->getKey(), 'subject_kind' => 'character', 'subject_id' => $this->lyra->getKey(), 'resources' => [], 'conditions' => [],
            'items' => [['id' => 'rope', 'label' => 'Corda', 'qty' => 1, 'note' => ''], ['id' => 'arrows', 'label' => 'Flechas', 'qty' => 12, 'note' => '']], 'revision' => 1]);
        $this->sessionId = $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'S'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    private function places(array $places, int $revision = 0, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->master)->putJson("/api/v1/sessions/{$this->sessionId}/places", ['revision' => $revision, 'places' => $places]);
    }

    private function offer(string $item = 'arrows', int $qty = 5, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->ana)->postJson("/api/v1/sessions/{$this->sessionId}/item-offers", ['from_character_id' => $this->lyra->getKey(), 'to_character_id' => $this->bram->getKey(), 'item_id' => $item, 'qty' => $qty]);
    }

    private function items(CampaignCharacter $c): array
    {
        return collect(LiveState::query()->where('subject_id', $c->getKey())->first()?->items ?? [])->mapWithKeys(static fn (array $i): array => [$i['label'] => $i['qty']])->all();
    }

    public function test_the_master_sets_places_and_players_see_them(): void
    {
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->lyra->getKey()]]], 0, $this->ana)->assertForbidden();
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->lyra->getKey()]], ['id' => 'taverna', 'name' => 'Na taverna', 'character_ids' => [$this->lyra->getKey()]]])->assertStatus(409)->assertJsonPath('code', 'places.character_invalid');
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->lyra->getKey()]]])->assertOk()->assertJsonPath('revision', 1);
        $this->places([], 0)->assertStatus(409)->assertJsonPath('code', 'places.revision_mismatch');
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk()->assertJsonPath('session.places.places.0.name', 'Na ponte');
    }

    public function test_a_nearby_player_offers_an_item_and_the_receiver_accepts_it(): void
    {
        $offer = $this->offer()->assertCreated()->assertJsonPath('status', 'pending');
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonPath('item_offers.0.item_label', 'Flechas');
        $this->actingAs($this->ana)->postJson("/api/v1/item-offers/{$offer->json('id')}/accept")->assertForbidden();
        $this->actingAs($this->bruno)->postJson("/api/v1/item-offers/{$offer->json('id')}/accept")->assertOk()->assertJsonPath('status', 'accepted');

        self::assertSame(['Corda' => 1, 'Flechas' => 7], $this->items($this->lyra));
        self::assertSame(['Flechas' => 5], $this->items($this->bram));
        $this->actingAs($this->bruno)->postJson("/api/v1/item-offers/{$offer->json('id')}/accept")->assertStatus(409);
    }

    public function test_far_characters_cannot_trade_and_nobody_offers_what_they_do_not_have(): void
    {
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->lyra->getKey()]]])->assertOk();
        $this->offer()->assertStatus(409)->assertJsonPath('code', 'item.too_far');
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->lyra->getKey(), $this->bram->getKey()]]], 1)->assertOk();
        $this->offer('arrows', 13)->assertStatus(409)->assertJsonPath('code', 'item.not_enough');
        $this->offer('arrows', 1, $this->bruno)->assertForbidden();
        $this->offer('rope', 1)->assertCreated();
    }

    public function test_an_offer_accepted_then_the_group_splits_is_refused_and_the_giver_may_withdraw(): void
    {
        $offer = $this->offer()->assertCreated();
        $this->places([['id' => 'ponte', 'name' => 'Na ponte', 'character_ids' => [$this->bram->getKey()]]])->assertOk();
        $this->actingAs($this->bruno)->postJson("/api/v1/item-offers/{$offer->json('id')}/accept")->assertStatus(409)->assertJsonPath('code', 'item.too_far');
        $this->actingAs($this->ana)->postJson("/api/v1/item-offers/{$offer->json('id')}/decline")->assertOk()->assertJsonPath('status', 'declined');
        self::assertSame(['Corda' => 1, 'Flechas' => 12], $this->items($this->lyra));
    }

    public function test_the_master_undoes_an_accepted_offer(): void
    {
        $offer = $this->offer('rope', 1)->assertCreated();
        $this->actingAs($this->bruno)->postJson("/api/v1/item-offers/{$offer->json('id')}/accept")->assertOk();
        $this->actingAs($this->bruno)->postJson("/api/v1/item-offers/{$offer->json('id')}/undo")->assertForbidden();
        $this->actingAs($this->master)->postJson("/api/v1/item-offers/{$offer->json('id')}/undo")->assertOk()->assertJsonPath('status', 'undone');
        self::assertEqualsCanonicalizing(['Corda' => 1, 'Flechas' => 12], $this->items($this->lyra));
        self::assertSame([], $this->items($this->bram));
    }
}
