<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Live\PublishSessionEvents;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignNpc;
use App\Models\SessionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-002 acceptance at the API level: sessions, rolls, visibility, whispers, recovery. */
final class LiveSessionApiTest extends TestCase
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
        $this->lyra->update(['name' => 'Lyra', 'sheet' => ['attributes' => ['agility' => 3, 'might' => 1, 'wits' => 2], 'skills' => ['climb' => 2, 'lore' => 0]]]);
        $this->bram = $this->makeCharacter($this->campaign, $this->bruno);
        $this->bram->update(['name' => 'Bram']);
        $this->sessionId = $this->openSession()->assertCreated()->json('id');
    }

    private function openSession(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'Sessão 1'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $overrides */
    private function requestRoll(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->master)->postJson("/api/v1/sessions/{$this->sessionId}/roll-requests", array_replace_recursive([
            'campaign_character_id' => $this->lyra->getKey(),
            'paths' => ['/attributes/agility', '/skills/climb'],
            'modifier' => 1,
            'dice' => ['faces' => 10, 'count_mode' => 'pool', 'reading' => 'successes', 'threshold' => 6, 'target' => 2],
        ], $overrides));
    }

    private function roll(string $requestId, int $revision = 0, ?string $key = null, ?User $as = null, array $extra = []): TestResponse
    {
        return $this->actingAs($as ?? $this->ana)->postJson("/api/v1/roll-requests/{$requestId}/rolls", ['expected_revision' => $revision, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @return list<array<string, mixed>> */
    private function eventsFor(User $user, int $after = 0): array
    {
        return $this->actingAs($user)->getJson("/api/v1/sessions/{$this->sessionId}/events?after={$after}")->assertOk()->json('data');
    }

    public function test_only_one_open_session_and_only_masters_start_it(): void
    {
        $this->openSession()->assertConflict()->assertJsonPath('code', 'session.already_open');
        $this->openSession($this->ana)->assertForbidden();
        $this->actingAs($this->makeUser())->getJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions/current")->assertNotFound();
        $this->actingAs($this->ana)->getJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions/current")
            ->assertOk()->assertJsonPath('session.status', 'active')->assertJsonMissingPath('session.compass');
    }

    public function test_pool_comes_from_the_official_sheet_and_the_roll_is_resolved_by_the_server(): void
    {
        $request = $this->requestRoll(['secret' => false])->assertCreated()->assertJsonPath('secret', false);
        // Agility 3 + Climb 2 + modifier 1 = six d10, fixed (no player choice).
        $request->assertJsonPath('max_dice', 6)->assertJsonPath('min_dice', 6)->assertJsonPath('components.0.value', 3)->assertJsonPath('components.1.label', 'Escalar');

        $roll = $this->roll($request->json('id'))->assertCreated();
        $faces = $roll->json('results');
        self::assertCount(6, $faces);
        $successes = count(array_filter($faces, static fn (int $f): bool => $f >= 6));
        $roll->assertJsonPath('successes', $successes)->assertJsonPath('succeeded', $successes >= 2)->assertJsonPath('attempt', 1);
        self::assertIsInt($roll->json('animation_seed'));

        // Everyone at the table sees the same result.
        $types = array_column($this->eventsFor($this->bruno), 'type');
        self::assertContains('roll.resolved', $types);
        $resolved = collect($this->eventsFor($this->master))->firstWhere('type', 'roll.resolved');
        self::assertSame($faces, $resolved['payload']['results']);
    }

    public function test_player_cannot_choose_faces_or_exceed_attempts(): void
    {
        $id = $this->requestRoll()->json('id');
        $this->roll($id, extra: ['dice_count' => 3])->assertConflict()->assertJsonPath('code', 'roll.dice_count_not_allowed');
        $this->roll($id)->assertCreated();
        $this->roll($id, 1)->assertConflict()->assertJsonPath('code', 'roll.request_not_open');
        // Unknown fields such as faces are rejected outright.
        $this->actingAs($this->ana)->postJson("/api/v1/roll-requests/{$id}/rolls", ['expected_revision' => 1, 'faces' => [10, 10]], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_retry_with_same_key_returns_the_original_roll(): void
    {
        $id = $this->requestRoll(['attempts' => 2])->json('id');
        $key = (string) Str::uuid();
        $first = $this->roll($id, 0, $key)->assertCreated();
        $again = $this->roll($id, 0, $key)->assertCreated();
        self::assertSame($first->json('id'), $again->json('id'));
        self::assertSame($first->json('results'), $again->json('results'));
        $this->roll($id, 1, $key)->assertConflict()->assertJsonPath('code', 'idempotency.key_reused');
    }

    public function test_only_the_owner_rolls_and_only_masters_request(): void
    {
        $id = $this->requestRoll()->json('id');
        $this->roll($id, as: $this->bruno)->assertNotFound();
        $this->requestRoll(as: $this->ana)->assertForbidden();
        $this->requestRoll(['paths' => ['/secrets/hook']])->assertUnprocessable()->assertJsonPath('code', 'roll.component_invalid');
    }

    public function test_player_choice_range_and_fixed_d20_bonus(): void
    {
        $choice = $this->requestRoll(['allows_choice' => true])->assertJsonPath('min_dice', 1)->json('id');
        $this->roll($choice, extra: ['dice_count' => 2])->assertCreated()->assertJsonCount(2, 'results');

        $d20 = $this->requestRoll(['dice' => ['faces' => 20, 'count_mode' => 'fixed', 'count' => 1, 'reading' => 'sum', 'target' => 15, 'threshold' => null]])->assertCreated();
        $d20->assertJsonPath('dice.bonus', 6)->assertJsonPath('max_dice', 1);
        $roll = $this->roll($d20->json('id'))->assertCreated();
        self::assertSame($roll->json('results.0') + 6, $roll->json('total'));
    }

    public function test_requests_are_between_player_and_master_by_default(): void
    {
        $id = $this->requestRoll()->assertCreated()->assertJsonPath('secret', true)->json('id');
        $roll = $this->roll($id)->assertCreated();
        self::assertNotContains('roll.requested', array_column($this->eventsFor($this->bruno), 'type'));
        self::assertNotContains('roll.resolved', array_column($this->eventsFor($this->bruno), 'type'));
        // The master can bring it to the table afterwards, with the numbers (it is a player roll).
        $this->actingAs($this->master)->postJson("/api/v1/official-rolls/{$roll->json('id')}/reveal")->assertOk();
        $revealed = collect($this->eventsFor($this->bruno))->firstWhere('type', 'roll.revealed');
        self::assertSame($roll->json('results'), $revealed['payload']['results']);
        self::assertSame(1, $revealed['payload']['modifier']);
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(1, 'rolls');
    }

    public function test_secret_request_reaches_only_master_and_roller(): void
    {
        $id = $this->requestRoll(['secret' => true])->json('id');
        $this->roll($id)->assertCreated();
        self::assertNotContains('roll.resolved', array_column($this->eventsFor($this->bruno), 'type'));
        self::assertContains('roll.resolved', array_column($this->eventsFor($this->ana), 'type'));
        self::assertContains('roll.resolved', array_column($this->eventsFor($this->master), 'type'));
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk()->assertJsonCount(0, 'rolls')->assertJsonCount(0, 'requests');
    }

    public function test_npc_rolls_are_secret_until_revealed_without_numbers(): void
    {
        $npc = CampaignNpc::query()->create(['campaign_id' => $this->campaign->getKey(), 'preset_version_id' => $this->campaign->preset_version_id, 'name' => 'Guardião', 'sheet' => ['attributes' => ['might' => 4]], 'sheet_revision' => 0, 'created_by' => $this->master->getKey()]);
        $roll = $this->actingAs($this->master)->postJson("/api/v1/sessions/{$this->sessionId}/npc-rolls", [
            'campaign_npc_id' => $npc->getKey(), 'paths' => ['/attributes/might'], 'modifier' => 2,
            'dice' => ['faces' => 10, 'count_mode' => 'pool', 'reading' => 'successes', 'threshold' => 6],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('secret', true)->assertJsonCount(6, 'results');
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(0, 'rolls')->assertJsonCount(0, 'npcs');

        $this->actingAs($this->ana)->postJson("/api/v1/official-rolls/{$roll->json('id')}/reveal")->assertForbidden();
        $this->actingAs($this->master)->postJson("/api/v1/official-rolls/{$roll->json('id')}/reveal")->assertOk();
        $revealed = collect($this->eventsFor($this->ana))->firstWhere('type', 'roll.revealed');
        self::assertSame($roll->json('results'), $revealed['payload']['results']);
        self::assertNull($revealed['payload']['modifier']);
        self::assertArrayNotHasKey('value', $revealed['payload']['components'][0]);
    }

    public function test_whispers_reach_only_their_recipient(): void
    {
        $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/whispers", ['recipient_user_id' => $this->ana->getKey(), 'body' => 'O guarda mente.'])->assertCreated();
        $this->actingAs($this->ana)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/whispers", ['recipient_user_id' => $this->master->getKey(), 'body' => 'Vou segui-lo.'])->assertCreated();
        $this->actingAs($this->ana)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/whispers", ['recipient_user_id' => $this->bruno->getKey(), 'body' => 'psiu'])->assertUnprocessable();

        self::assertNotContains('whisper.received', array_column($this->eventsFor($this->bruno), 'type'));
        self::assertCount(2, array_filter($this->eventsFor($this->ana), static fn (array $e): bool => $e['type'] === 'whisper.received'));
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(0, 'whispers');
        $this->actingAs($this->master)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(2, 'whispers');
        self::assertSame(0, SessionEvent::query()->where('type', 'whisper.received')->where('audience', 'table')->count());
    }

    public function test_players_cannot_read_or_change_master_data(): void
    {
        $base = "/api/v1/campaigns/{$this->campaign->getKey()}";
        $this->actingAs($this->ana)->getJson("{$base}/notes")->assertForbidden();
        $this->actingAs($this->ana)->postJson("{$base}/notes", ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->ana)->putJson("{$base}/live-states/character/{$this->lyra->getKey()}", ['revision' => 0, 'resources' => [], 'conditions' => []])->assertForbidden();
        $this->actingAs($this->ana)->putJson("/api/v1/sessions/{$this->sessionId}/compass", ['revision' => 1, 'compass' => []])->assertForbidden();
        $this->actingAs($this->makeUser())->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertNotFound();
        $snapshot = $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk();
        $snapshot->assertJsonPath('role', 'player')->assertJsonMissingPath('session.compass')->assertJsonCount(0, 'notes');
    }

    public function test_notes_autosave_detects_conflicts(): void
    {
        $note = $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/notes", ['body' => 'Nome: Velha Corva', 'tags' => ['PdM']])->assertCreated();
        $this->actingAs($this->master)->patchJson("/api/v1/notes/{$note->json('id')}", ['body' => 'v2', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->actingAs($this->master)->patchJson("/api/v1/notes/{$note->json('id')}", ['body' => 'stale', 'revision' => 1])->assertConflict()->assertJsonPath('current_revision', 2);
        self::assertNotContains('note.saved', array_column($this->eventsFor($this->ana), 'type'));
    }

    public function test_live_state_is_table_visible_for_characters_and_conflict_checked(): void
    {
        $url = "/api/v1/campaigns/{$this->campaign->getKey()}/live-states/character/{$this->lyra->getKey()}";
        $body = ['revision' => 0, 'resources' => [['id' => 'vida', 'label' => 'Vida', 'current' => 5, 'max' => 7]], 'conditions' => ['Ferida']];
        $this->actingAs($this->master)->putJson($url, $body)->assertOk()->assertJsonPath('live.revision', 1);
        $this->actingAs($this->master)->putJson($url, $body)->assertConflict();
        $live = collect($this->eventsFor($this->bruno))->firstWhere('type', 'live.updated');
        self::assertSame(['Ferida'], $live['payload']['live']['conditions']);
    }

    public function test_clue_whispered_to_one_player_stays_hidden_from_others(): void
    {
        $clue = $this->actingAs($this->master)->postJson("/api/v1/sessions/{$this->sessionId}/clues", ['title' => 'Selo partido', 'body' => 'O selo é recente.'])->assertCreated();
        $this->actingAs($this->master)->postJson("/api/v1/clues/{$clue->json('id')}/reveal", ['to' => 'user', 'user_id' => $this->ana->getKey()])->assertOk();
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(0, 'clues');
        $this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(1, 'clues')->assertJsonCount(1, 'whispers');
        $this->actingAs($this->master)->postJson("/api/v1/clues/{$clue->json('id')}/reveal", ['to' => 'table'])->assertOk();
        $this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertJsonCount(1, 'clues');
    }

    public function test_suggestion_becomes_a_request_in_one_tap(): void
    {
        $s = $this->actingAs($this->ana)->postJson("/api/v1/sessions/{$this->sessionId}/suggestions", ['campaign_character_id' => $this->lyra->getKey(), 'paths' => ['/attributes/agility', '/skills/climb'], 'note' => 'Subo pela hera'])->assertCreated();
        $this->actingAs($this->bruno)->postJson("/api/v1/sessions/{$this->sessionId}/suggestions", ['campaign_character_id' => $this->lyra->getKey(), 'paths' => ['/attributes/agility']])->assertNotFound();
        $this->requestRoll(['suggestion_id' => $s->json('id')])->assertCreated();
        $this->requestRoll(['suggestion_id' => $s->json('id')])->assertUnprocessable()->assertJsonPath('code', 'suggestion.unavailable');
    }

    public function test_sequence_is_gap_free_and_recovery_is_filtered(): void
    {
        $this->requestRoll(['secret' => true]);
        $this->requestRoll(['secret' => false]);
        $seqs = SessionEvent::query()->where('session_id', $this->sessionId)->orderBy('seq')->pluck('seq')->all();
        self::assertSame(range(1, count($seqs)), $seqs);
        $bruno = $this->eventsFor($this->bruno);
        self::assertLessThan(count($seqs), count($bruno));
        self::assertLessThan(count($seqs), count($this->eventsFor($this->master)));
        $last = end($bruno)['seq'];
        self::assertSame([], $this->eventsFor($this->bruno, $last));
    }

    public function test_paused_session_rejects_rolls_and_end_closes_requests(): void
    {
        $id = $this->requestRoll()->json('id');
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$this->sessionId}/pause")->assertOk()->assertJsonPath('status', 'paused');
        $this->roll($id)->assertConflict()->assertJsonPath('code', 'session.paused');
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$this->sessionId}/end")->assertOk()->assertJsonPath('status', 'ended');
        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$this->sessionId}/resume")->assertConflict();
        $this->openSession()->assertCreated();
    }

    public function test_events_are_published_after_commit(): void
    {
        Bus::fake([PublishSessionEvents::class]);
        $this->requestRoll()->assertCreated();
        Bus::assertDispatched(PublishSessionEvents::class);
    }
}
