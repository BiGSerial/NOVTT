<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\Support\DrivesProposals;
use Tests\TestCase;

/**
 * Preset engine Sprint 001 through the API on the authorial demo-linhagens preset: creation gate
 * and derived values, purchase with master approval (test 3), permissions, session lock and
 * history (test 6).
 */
final class PresetEngineApiTest extends TestCase
{
    use BuildsDomain;
    use DrivesProposals;
    use RefreshDatabase;

    private User $team;
    private User $master;
    private User $player;
    private Campaign $campaign;
    private CampaignCharacter $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = $this->makeUser('Equipe');
        $this->master = $this->makeUser('Mestre');
        $this->player = $this->makeUser('Ana');
        $this->campaign = $this->makeCampaign($this->master, $this->publishDemoPreset($this->team, 'demo-linhagens'));
        $this->addMember($this->campaign, $this->player);
        $this->character = $this->makeCharacter($this->campaign, $this->player);
    }

    private function created(): void
    {
        $this->applyAsProposal($this->player, $this->master, $this->character, self::linhagensCreation());
        $this->approveCharacter($this->master, $this->character)->assertOk()->assertJsonPath('status', 'approved');
    }

    /** @return list<array<string, mixed>> */
    private function xpLedger(): array
    {
        return array_values(array_filter((array) ($this->character->refresh()->sheet['_ledger'] ?? []), static fn (array $e): bool => $e['budget'] === 'xp'));
    }

    // Creation gate, derived values ---------------------------------------------------------------

    public function test_an_incomplete_character_is_not_activated_and_a_complete_one_is(): void
    {
        $this->applyAsProposal($this->player, $this->master, $this->character, [self::op('n', '/identity/name', 'Iara')]);
        $refused = $this->approveCharacter($this->master, $this->character)->assertStatus(422)->assertJsonPath('code', 'character.creation_incomplete');
        $this->assertContains('creation.step_incomplete', array_column($refused->json('validation.issues'), 'rule_id'));
        $this->assertSame([true, false], array_slice(array_column($refused->json('steps'), 'complete'), 0, 2));
        $this->assertNotSame('approved', $this->character->refresh()->status->value);

        $this->applyAsProposal($this->player, $this->master, $this->character, array_slice(self::linhagensCreation(), 1));
        $this->approveCharacter($this->master, $this->character)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('audit_events', ['action' => 'campaign_character.approved', 'subject_id' => $this->character->getKey()]);
    }

    public function test_responses_carry_derived_values_creation_progress_and_trace_but_storage_does_not(): void
    {
        $this->created();
        $view = $this->actingAs($this->player)->getJson("/api/v1/campaign-characters/{$this->character->getKey()}")->assertOk();

        $view->assertJsonPath('sheet.derived.vitality', 6)          // vigor 3 + 3
            ->assertJsonPath('sheet.derived.initiative', 6)           // destreza 4 + raciocinio 2
            ->assertJsonPath('sheet.derived.load_capacity', 15)
            ->assertJsonPath('creation.complete', true);
        $this->assertSame(['vitality', 'initiative', 'load_capacity'], array_column($view->json('decision_trace'), 'derived'));
        $this->assertArrayNotHasKey('derived', $this->character->refresh()->sheet, 'derived values are never stored');
        $this->assertStringNotContainsString('_ledger', (string) $view->getContent());
    }

    public function test_writing_a_derived_value_is_rejected(): void
    {
        $this->saveDraft($this->player, $this->character, [self::op('x', '/derived/vitality', 99)])->assertOk();
        $this->submitDraft($this->player, $this->character)->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'op.derived_read_only');
    }

    // Test 3: purchase with master approval -------------------------------------------------------

    public function test_master_rejection_does_not_debit_and_approval_debits_upgrades_and_audits_together(): void
    {
        $this->created();
        $this->grantBudget($this->master, $this->character, 'xp', 12)->assertCreated();
        $revisions = DB::table('character_sheet_revisions')->count();

        $rejected = $this->propose($this->player, $this->character, [self::op('up', '/attributes/physical/forca', 4)]);
        $this->decideEach($this->master, $rejected, ['up'], 'rejected')->assertOk();
        $this->finalizeRequest($this->master, $rejected)->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame([], $this->xpLedger(), 'rejection does not debit');
        $this->assertSame(3, $this->character->refresh()->sheet['attributes']['physical']['forca']);
        $this->assertSame($revisions, DB::table('character_sheet_revisions')->count());

        $approved = $this->propose($this->player, $this->character, [self::op('up', '/attributes/physical/forca', 4)]);
        $this->decideEach($this->master, $approved, ['up'])->assertOk();
        $this->finalizeRequest($this->master, $approved)->assertOk()->assertJsonPath('status', 'applied');

        $character = $this->character->refresh();
        $this->assertSame(4, $character->sheet['attributes']['physical']['forca']);
        $this->assertEquals([['path' => '/attributes/physical/forca', 'level' => 4, 'budget' => 'xp', 'cost' => 12, 'rule' => 'physical_xp']], $this->xpLedger());
        $this->assertSame($revisions + 1, DB::table('character_sheet_revisions')->count());
        $audit = DB::table('audit_events')->where('action', 'change_request.applied')->where('subject_id', $approved)->sole();
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame('change_request', $metadata['origin']);
        $this->assertSame($this->player->getKey(), $metadata['author_user_id']);
        $this->assertSame($character->preset_version_id, $metadata['preset_version_id']);
        $this->assertSame('original-2026', $metadata['rules_revision']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $metadata['preset_content_hash']);
    }

    public function test_insufficient_balance_blocks_the_proposal_and_its_application(): void
    {
        $this->created();
        $this->grantBudget($this->master, $this->character, 'xp', 11)->assertCreated();
        $this->saveDraft($this->player, $this->character, [self::op('up', '/attributes/physical/forca', 4)])->assertOk();
        $this->submitDraft($this->player, $this->character)->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'budget.insufficient')
            ->assertJsonPath('validation.issues.0.params.required', 12);

        // Enough when submitted, then a correction lowers the balance: finalize applies nothing.
        $this->grantBudget($this->master, $this->character, 'xp', 1)->assertCreated();
        $id = $this->propose($this->player, $this->character, [self::op('up', '/attributes/physical/forca', 4)]);
        $this->grantBudget($this->master, $this->character, 'xp', -1)->assertCreated();
        $this->decideEach($this->master, $id, ['up'])->assertOk();
        $this->finalizeRequest($this->master, $id)->assertStatus(422)->assertJsonPath('code', 'change_request.approved_set_invalid');
        $this->assertSame([], $this->xpLedger());
        $this->assertSame(3, $this->character->refresh()->sheet['attributes']['physical']['forca']);
    }

    // Test 6: permissions, identity, session lock, history -------------------------------------

    public function test_players_cannot_write_sheets_outside_proposals(): void
    {
        $this->created();
        $before = $this->character->refresh()->sheet;
        foreach (['putJson', 'patchJson'] as $method) {
            $status = $this->actingAs($this->player)->{$method}("/api/v1/campaign-characters/{$this->character->getKey()}", ['sheet' => ['attributes' => ['physical' => ['forca' => 5]]]])->status();
            $this->assertContains($status, [404, 405], $method);
        }
        $this->assertSame($before, $this->character->refresh()->sheet);
    }

    public function test_the_author_is_always_the_authenticated_user(): void
    {
        // The player's draft exists; the master tries to submit it in the player's name.
        $this->saveDraft($this->player, $this->character, [self::op('n', '/identity/name', 'Iara')])->assertOk();
        $this->saveDraft($this->master, $this->character, [self::op('n', '/identity/name', 'Outro')]);
        $id = $this->submitDraft($this->master, $this->character, ['author_user_id' => $this->player->getKey()])->json('id');
        if ($id !== null) {
            $this->assertSame($this->master->getKey(), DB::table('change_requests')->where('id', $id)->value('author_user_id'));
        }
        $this->assertSame(0, DB::table('change_requests')->where('author_user_id', $this->player->getKey())->count(), 'nobody can submit in the player\'s name');
    }

    public function test_an_active_live_session_locks_player_proposals_until_it_ends(): void
    {
        $this->created();
        $this->grantBudget($this->master, $this->character, 'xp', 12)->assertCreated();
        $session = $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'Sessão 1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertContains($session->status(), [200, 201], (string) $session->getContent());
        $sessionId = (string) DB::table('game_sessions')->where('campaign_id', $this->campaign->getKey())->value('id');
        $this->assertSame($this->campaign->preset_version_id, DB::table('game_sessions')->where('id', $sessionId)->value('preset_version_id'), 'session snapshot of the pinned preset');

        $this->saveDraft($this->player, $this->character, [self::op('up', '/attributes/physical/forca', 4)])->assertOk();
        $this->submitDraft($this->player, $this->character)->assertStatus(409)->assertJsonPath('code', 'character.locked_by_session');

        $this->actingAs($this->master)->postJson("/api/v1/sessions/{$sessionId}/end", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->submitDraft($this->player, $this->character)->assertCreated();
    }

    public function test_a_character_in_creation_can_be_sent_during_a_live_session(): void
    {
        $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'Sessão 1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->saveDraft($this->player, $this->character, self::linhagensCreation())->assertOk();
        $this->submitDraft($this->player, $this->character)->assertCreated();
    }

    public function test_history_keeps_each_applied_snapshot_with_its_origin(): void
    {
        $this->created();
        $revision = DB::table('character_sheet_revisions')->where('campaign_character_id', $this->character->getKey())->orderByDesc('revision')->first();
        $this->assertNotNull($revision->change_request_id);
        $snapshot = json_decode((string) $revision->sheet, true);
        $this->assertSame('Iara', $snapshot['identity']['name']);
        $this->assertSame((array) $this->character->refresh()->sheet, $snapshot);
        $approved = json_decode((string) DB::table('audit_events')->where('action', 'campaign_character.approved')->value('metadata'), true);
        $this->assertSame($this->character->preset_version_id, $approved['preset_version_id']);
    }
}
