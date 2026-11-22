<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class ReviewWorkflowApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;
    private User $player;
    private Campaign $campaign;
    private CampaignCharacter $character;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->makeUser('Mestre');
        $this->player = $this->makeUser('Ana');
        $this->campaign = $this->makeCampaign($this->master);
        $this->addMember($this->campaign, $this->player);
        $this->character = $this->makeCharacter($this->campaign, $this->player);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/campaign-characters/{$this->character->getKey()}{$suffix}";
    }

    /** @param list<array<string, mixed>> $operations */
    private function draft(array $operations, int $draftVersion = 0, ?int $base = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->putJson($this->url('/draft'), [
            'draft_version' => $draftVersion,
            'change_set' => ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => $base ?? $this->character->refresh()->sheet_revision, 'operations' => $operations],
        ]);
    }

    private function submit(int $draftVersion, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->player)->postJson($this->url('/change-requests'), ['draft_version' => $draftVersion], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @param list<array<string, mixed>> $decisions */
    private function decide(string $requestId, int $reviewVersion, array $decisions): TestResponse
    {
        return $this->actingAs($this->master)->putJson("/api/v1/change-requests/{$requestId}/decisions", ['review_version' => $reviewVersion, 'decisions' => $decisions]);
    }

    private function finalize(string $requestId, int $reviewVersion, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->master)->postJson("/api/v1/change-requests/{$requestId}/finalize", ['review_version' => $reviewVersion], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @return list<array<string, mixed>> */
    private static function creationOps(): array
    {
        return [
            ['id' => 'name', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Ana'],
            ['id' => 'might', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 3],
            ['id' => 'agility', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 2],
            ['id' => 'climb', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 2],
            ['id' => 'merit', 'type' => 'add_item', 'path' => '/merits', 'item_id' => 'm1', 'value' => ['name' => 'Ágil', 'cost' => 2]],
        ];
    }

    /** Drafts and submits the creation set; returns the change request id. */
    private function submitted(): string
    {
        $this->draft(self::creationOps())->assertOk()->assertJsonPath('validation.valid', true);

        return (string) $this->submit(1)->assertCreated()->json('id');
    }

    public function test_full_review_with_partial_approval_applies_atomically_and_keeps_rejections(): void
    {
        $id = $this->submitted();
        $this->assertSame('pending_approval', $this->character->refresh()->status->value);

        $view = $this->actingAs($this->master)->getJson("/api/v1/change-requests/{$id}")->assertOk();
        $this->assertSame(['identity', 'attributes', 'skills', 'merits'], array_column($view->json('sections'), 'section_id'));
        $this->assertSame('Atributos', $view->json('sections.1.label'));

        $this->decide($id, 1, [
            ['operation_ids' => ['might', 'agility'], 'decision' => 'approved'],  // batch within one section
            ['operation_ids' => ['name'], 'decision' => 'approved'],
            ['operation_ids' => ['climb'], 'decision' => 'approved'],
            ['operation_ids' => ['merit'], 'decision' => 'rejected', 'reason' => 'Qualidade não combina com o conceito.'],
        ])->assertOk()->assertJsonPath('status', 'awaiting_resolution')->assertJsonPath('review_version', 2);
        $this->assertSame(0, $this->character->refresh()->sheet_revision, 'decisions never apply by themselves');

        $done = $this->finalize($id, 2)->assertOk()->assertJsonPath('status', 'applied')->assertJsonPath('resulting_sheet_revision', 1);
        $this->assertSame('Qualidade não combina com o conceito.', collect($done->json('sections'))->firstWhere('section_id', 'merits')['operations'][0]['decision']['reason']);

        $character = $this->character->refresh();
        $this->assertSame(1, $character->sheet_revision);
        $this->assertSame(3, $character->sheet['attributes']['might']);
        $this->assertArrayNotHasKey('merits', $character->sheet, 'rejected operation not applied');
        $this->assertNotEmpty($character->sheet['_ledger'], 'per-increment provenance stored');
        $this->assertDatabaseHas('character_sheet_revisions', ['campaign_character_id' => $character->getKey(), 'revision' => 1, 'change_request_id' => $id]);
        $this->assertSame(5, DB::table('operation_decisions')->where('change_request_id', $id)->where('is_final', true)->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'character.sheet_revised', 'aggregate_id' => $character->getKey()]);
        $this->assertStringNotContainsString('Ágil', (string) DB::table('outbox_events')->where('event_type', 'character.sheet_revised')->value('payload'));

        // Final decisions are immutable; the player sees the rejection reason; ledger never leaks.
        $this->decide($id, 3, [['operation_ids' => ['merit'], 'decision' => 'approved', 'override' => true]])->assertStatus(409)->assertJsonPath('code', 'change_request.not_active');
        $this->actingAs($this->player)->getJson("/api/v1/change-requests/{$id}")->assertOk()->assertJsonPath('sections.3.operations.0.decision.decision', 'rejected');
        $this->assertStringNotContainsString('_ledger', (string) $this->actingAs($this->player)->getJson($this->url())->getContent());

        $this->actingAs($this->master)->postJson($this->url('/approve'))->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_batch_rules_individual_rejection_reasons_and_no_silent_override(): void
    {
        $id = $this->submitted();

        $this->decide($id, 1, [['operation_ids' => ['might', 'climb'], 'decision' => 'approved']])->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'decision.batch_cross_section');
        $this->decide($id, 1, [['operation_ids' => ['might', 'agility'], 'decision' => 'rejected', 'reason' => 'Motivo compartilhado aqui.']])->assertStatus(422);
        $this->decide($id, 1, [['operation_ids' => ['might'], 'decision' => 'rejected', 'reason' => 'curto']])->assertStatus(422);
        $this->decide($id, 1, [['operation_ids' => ['might'], 'decision' => 'rejected']])->assertStatus(422);

        $this->decide($id, 1, [['operation_ids' => ['might'], 'decision' => 'rejected', 'reason' => 'Vigor alto demais para o conceito.']])->assertOk()->assertJsonPath('status', 'in_review');
        $this->decide($id, 1, [['operation_ids' => ['agility'], 'decision' => 'approved']])->assertStatus(409)->assertJsonPath('code', 'change_request.review_version_conflict')->assertJsonPath('current_revision', 2);
        $this->decide($id, 2, [['operation_ids' => ['might', 'agility'], 'decision' => 'approved']])->assertStatus(409)->assertJsonPath('code', 'decision.rejection_requires_individual_override');
        $this->decide($id, 2, [['operation_ids' => ['might'], 'decision' => 'approved', 'override' => true]])->assertOk();

        $this->finalize($id, 3)->assertStatus(409)->assertJsonPath('code', 'decision.incomplete');
        $this->actingAs($this->player)->putJson("/api/v1/change-requests/{$id}/decisions", ['review_version' => 3, 'decisions' => [['operation_ids' => ['might'], 'decision' => 'approved']]])->assertNotFound();
    }

    /** Applies a set of operations through the full review flow, approving everything. */
    private function applyAll(array $operations): void
    {
        $draft = $this->draft($operations, (int) ($this->actingAs($this->player)->getJson($this->url('/draft'))->json('draft_version') ?? 0))->assertOk();
        $id = (string) $this->submit((int) $draft->json('draft_version'))->assertCreated()->json('id');
        $sections = $this->actingAs($this->master)->getJson("/api/v1/change-requests/{$id}")->json('sections');
        $decisions = array_map(static fn (array $s): array => ['operation_ids' => array_column($s['operations'], 'id'), 'decision' => 'approved'], $sections);
        $this->decide($id, 1, $decisions)->assertOk();
        $this->finalize($id, 2)->assertOk();
    }

    public function test_invalid_approved_subset_changes_nothing(): void
    {
        // Creation: might 4 uses 3 attribute points; agility 3 uses the last one plus 5 bonus.
        $this->applyAll([
            ['id' => 'm', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 4],
            ['id' => 'a', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 3],
            ['id' => 'w', 'type' => 'set_field', 'path' => '/attributes/wits', 'value' => 1], // free dot; lore depends on it
        ]);
        // Second proposal across sections: lowering agility refunds 5 bonus, which pays for lore
        // (skill points cover climb). Rejecting the refund makes the approved lore unaffordable.
        $this->draft([
            ['id' => 'down', 'type' => 'set_field', 'path' => '/attributes/agility', 'value' => 2, 'expected' => 3],
            ['id' => 'climb', 'type' => 'set_field', 'path' => '/skills/climb', 'value' => 3],
            ['id' => 'lore', 'type' => 'set_field', 'path' => '/skills/lore', 'value' => 2],
        ])->assertOk()->assertJsonPath('validation.valid', true);
        $id = (string) $this->submit(1)->assertCreated()->json('id');
        $this->decide($id, 1, [
            ['operation_ids' => ['down'], 'decision' => 'rejected', 'reason' => 'Agilidade é marca do personagem.'],
            ['operation_ids' => ['climb', 'lore'], 'decision' => 'approved'],
        ])->assertOk();
        $before = $this->character->refresh()->sheet;

        $this->finalize($id, 2)->assertStatus(422)->assertJsonPath('code', 'change_request.approved_set_invalid')
            ->assertJsonPath('validation.issues.0.rule_id', 'budget.insufficient')
            ->assertJsonPath('validation.issues.0.operation_ids', ['lore']);
        $this->assertSame(1, $this->character->refresh()->sheet_revision);
        $this->assertSame($before, $this->character->sheet);
        $this->assertSame(0, DB::table('operation_decisions')->where('change_request_id', $id)->where('is_final', true)->count(), 'decisions stay provisional');
        $this->assertSame('awaiting_resolution', DB::table('change_requests')->where('id', $id)->value('status'));

        // The master revisits the decision individually; the full set then applies.
        $this->decide($id, 2, [['operation_ids' => ['down'], 'decision' => 'approved', 'override' => true]])->assertOk();
        $this->finalize($id, 3)->assertOk()->assertJsonPath('resulting_sheet_revision', 2);
    }

    public function test_failure_midway_rolls_everything_back(): void
    {
        $id = $this->submitted();
        $this->decide($id, 1, [
            ['operation_ids' => ['name'], 'decision' => 'approved'], ['operation_ids' => ['might', 'agility'], 'decision' => 'approved'],
            ['operation_ids' => ['climb'], 'decision' => 'approved'], ['operation_ids' => ['merit'], 'decision' => 'approved'],
        ])->assertOk();
        // Make the very last write of finalization (the outbox) fail.
        DB::unprepared("CREATE FUNCTION test_fail() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'boom'; END; \$\$;
            CREATE TRIGGER test_outbox_fail BEFORE INSERT ON outbox_events FOR EACH ROW EXECUTE FUNCTION test_fail();");

        $this->withoutExceptionHandling();
        try {
            $this->finalize($id, 2);
            $this->fail('expected failure');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame(0, $this->character->refresh()->sheet_revision);
        $this->assertSame([], array_diff_key((array) $this->character->sheet, ['_' => 1]));
        $this->assertSame(0, DB::table('character_sheet_revisions')->count());
        $this->assertSame(0, DB::table('operation_decisions')->where('is_final', true)->count());
        $this->assertSame('awaiting_resolution', DB::table('change_requests')->where('id', $id)->value('status'));
    }

    public function test_full_rejection_creates_no_revision(): void
    {
        $this->draft([['id' => 'name', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'X']])->assertOk();
        $id = (string) $this->submit(1)->json('id');
        $this->decide($id, 1, [['operation_ids' => ['name'], 'decision' => 'rejected', 'reason' => 'Nome não pertence ao cenário.']])->assertOk();

        $this->finalize($id, 2)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('resulting_sheet_revision', null);
        $this->assertSame(0, $this->character->refresh()->sheet_revision);
        $this->assertSame(0, DB::table('character_sheet_revisions')->count());
    }

    public function test_one_active_proposal_and_idempotent_submit_and_finalize(): void
    {
        $this->draft(self::creationOps())->assertOk();
        $key = (string) Str::uuid();
        $id = (string) $this->submit(1, $key)->assertCreated()->json('id');
        $this->submit(1, $key)->assertCreated()->assertJsonPath('id', $id); // replay

        $this->draft([['id' => 'n', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Bia']])->assertOk();
        $this->submit(1)->assertStatus(409)->assertJsonPath('code', 'change_request.active_exists');

        $this->decide($id, 1, [
            ['operation_ids' => ['name'], 'decision' => 'approved'], ['operation_ids' => ['might', 'agility'], 'decision' => 'approved'],
            ['operation_ids' => ['climb'], 'decision' => 'approved'], ['operation_ids' => ['merit'], 'decision' => 'approved'],
        ])->assertOk();
        $finalizeKey = (string) Str::uuid();
        $this->finalize($id, 2, $finalizeKey)->assertOk()->assertJsonPath('resulting_sheet_revision', 1);
        $this->finalize($id, 2, $finalizeKey)->assertOk()->assertJsonPath('resulting_sheet_revision', 1); // replay, no second apply
        $this->finalize($id, 2)->assertStatus(409)->assertJsonPath('code', 'change_request.not_active');
        $this->assertSame(1, $this->character->refresh()->sheet_revision);
        $this->assertSame(1, DB::table('character_sheet_revisions')->count());
    }

    public function test_players_cannot_target_or_discover_private_fields(): void
    {
        $this->draft([['id' => 's', 'type' => 'set_field', 'path' => '/secrets/hook', 'value' => 'x']])->assertOk()
            ->assertJsonPath('validation.valid', false)->assertJsonPath('validation.issues.0.rule_id', 'op.path_not_allowed')
            ->assertJsonPath('validation.issues.0.paths', []);
        $hidden = $this->draft([['id' => 'm', 'type' => 'set_field', 'path' => '/master/notes', 'value' => 'x']], 1)->json();
        $undeclared = $this->draft([['id' => 'm', 'type' => 'set_field', 'path' => '/nope/field', 'value' => 'x']], 2)->json();
        $this->assertSame($hidden['validation'], $undeclared['validation'], 'hidden and nonexistent paths are indistinguishable');
        $this->submit(3)->assertStatus(422);

        // Masters (e.g. under custody) may edit master-only fields.
        $this->actingAs($this->master)->putJson($this->url('/custody'))->assertOk();
        $this->draft([['id' => 'm', 'type' => 'set_field', 'path' => '/master/notes', 'value' => 'Segredo']], 0, as: $this->master)->assertOk()->assertJsonPath('validation.valid', true);
        $this->actingAs($this->player)->putJson($this->url('/draft'), ['draft_version' => 3, 'change_set' => ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0, 'operations' => []]])
            ->assertStatus(409)->assertJsonPath('code', 'character.not_editable_by_actor');
    }

    public function test_after_approval_permanent_changes_need_granted_budget_and_review(): void
    {
        $id = $this->submitted();
        $this->decide($id, 1, [
            ['operation_ids' => ['name'], 'decision' => 'approved'], ['operation_ids' => ['might', 'agility'], 'decision' => 'approved'],
            ['operation_ids' => ['climb'], 'decision' => 'approved'], ['operation_ids' => ['merit'], 'decision' => 'approved'],
        ])->assertOk();
        $this->finalize($id, 2)->assertOk();
        $this->actingAs($this->master)->postJson($this->url('/approve'))->assertOk();

        // Advancement: might 3 -> 4 costs 12 XP; the player has none.
        $this->draft([['id' => 'up', 'type' => 'set_field', 'path' => '/attributes/might', 'value' => 4, 'expected' => 3]])->assertOk()
            ->assertJsonPath('validation.issues.0.rule_id', 'budget.insufficient')->assertJsonPath('validation.issues.0.params.budget', 'xp');
        $this->actingAs($this->player)->postJson($this->url('/grants'), ['budget_id' => 'xp', 'amount' => 50], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        $this->actingAs($this->master)->postJson($this->url('/grants'), ['budget_id' => 'xp', 'amount' => 12, 'reason' => 'Sessão 1'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($this->master)->postJson($this->url('/grants'), ['budget_id' => 'mana', 'amount' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame(3, $this->character->refresh()->sheet['attributes']['might'], 'granting XP spends nothing');
        $this->actingAs($this->player)->getJson($this->url('/grants'))->assertOk()->assertJsonPath('balances.xp', 12);

        $draft = $this->actingAs($this->player)->getJson($this->url('/draft'))->assertOk()->assertJsonPath('validation.valid', true);
        $next = (string) $this->submit((int) $draft->json('draft_version'))->assertCreated()->json('id');
        $this->decide($next, 1, [['operation_ids' => ['up'], 'decision' => 'approved']])->assertOk();
        $this->finalize($next, 2)->assertOk()->assertJsonPath('resulting_sheet_revision', 2);
        $this->actingAs($this->player)->getJson($this->url('/grants'))->assertOk()->assertJsonPath('balances.xp', 0);
    }

    public function test_stale_draft_requires_explicit_rebase_with_three_way_conflicts(): void
    {
        // A draft prepared at revision 0 ...
        $this->draft([
            ['id' => 'name', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Ana', 'expected' => null],
            ['id' => 'concept', 'type' => 'set_field', 'path' => '/identity/concept', 'value' => 'Batedora'],
        ])->assertOk();
        // ... while another change (here under custody by the master) lands first.
        $this->actingAs($this->master)->putJson($this->url('/custody'))->assertOk();
        $this->draft([['id' => 'n', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Anabela']], 0, as: $this->master)->assertOk();
        $masterRequest = (string) $this->submit(1, as: $this->master)->json('id');
        $this->decide($masterRequest, 1, [['operation_ids' => ['n'], 'decision' => 'approved']])->assertOk();
        $this->finalize($masterRequest, 2)->assertOk();
        $this->actingAs($this->master)->deleteJson($this->url('/custody'))->assertOk();

        $this->submit(1)->assertStatus(409)->assertJsonPath('code', 'draft.stale')->assertJsonPath('current_revision', 1);
        $before = $this->character->refresh()->sheet;
        $preview = $this->actingAs($this->player)->postJson($this->url('/draft/rebase-preview'))->assertOk();
        $this->assertSame($before, $this->character->refresh()->sheet, 'preview does not mutate');
        $preview->assertJsonPath('current_sheet_revision', 1)->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.operation_id', 'name')->assertJsonPath('conflicts.0.kind', 'value_changed')
            ->assertJsonPath('conflicts.0.current', 'Anabela')->assertJsonPath('conflicts.0.mine', 'Ana');

        $this->actingAs($this->player)->postJson($this->url('/draft/rebase'), ['preview_token' => $preview->json('preview_token'), 'resolutions' => []])
            ->assertStatus(422)->assertJsonPath('validation.issues.0.rule_id', 'rebase.resolution_missing');
        $this->actingAs($this->player)->postJson($this->url('/draft/rebase'), ['preview_token' => 'forged', 'resolutions' => [['operation_id' => 'name', 'resolution' => 'take_current']]])
            ->assertStatus(409)->assertJsonPath('code', 'rebase.preview_outdated');
        $rebased = $this->actingAs($this->player)->postJson($this->url('/draft/rebase'), ['preview_token' => $preview->json('preview_token'), 'resolutions' => [['operation_id' => 'name', 'resolution' => 'take_current']]])
            ->assertOk()->assertJsonPath('base_sheet_revision', 1)->assertJsonCount(1, 'change_set.operations');

        $this->submit((int) $rebased->json('draft_version'))->assertCreated();
    }

    public function test_rejected_operations_do_not_return_automatically_after_rebase(): void
    {
        $this->draft([['id' => 'c', 'type' => 'set_field', 'path' => '/identity/concept', 'value' => 'Pirata']])->assertOk();
        $first = (string) $this->submit(1)->json('id');
        $this->decide($first, 1, [['operation_ids' => ['c'], 'decision' => 'rejected', 'reason' => 'Pirata não existe neste cenário.']])->assertOk();
        $this->finalize($first, 2)->assertOk();

        // A new draft that re-proposes the very same change is flagged, never silently carried.
        $this->draft([['id' => 'again', 'type' => 'set_field', 'path' => '/identity/concept', 'value' => 'Pirata']], 0, 0)->assertOk();
        $this->actingAs($this->player)->postJson($this->url('/draft/rebase-preview'))->assertOk()
            ->assertJsonPath('conflicts.0.kind', 'previously_rejected');
    }

    public function test_draft_reports_remaining_balance_per_budget(): void
    {
        $this->actingAs($this->player)->getJson($this->url('/grants'))->assertOk()->assertJsonPath('balances.attribute_points', 4);

        // Vigor 3 and Agilidade 2 (first dot free): 3 of 4 attribute points; Escalar 2: 2 of 3.
        $saved = $this->draft(self::creationOps())->assertOk()->assertJsonPath('validation.valid', true);
        $saved->assertJsonPath('balances.attribute_points', 1)->assertJsonPath('balances.skill_points', 1)->assertJsonPath('balances.bonus', 5);
        $this->actingAs($this->player)->getJson($this->url('/draft'))->assertOk()->assertJsonPath('balances.attribute_points', 1);

        // Over budget: the draft is invalid and the balance never goes below zero.
        $over = $this->draft([...self::creationOps(), ['id' => 'wits', 'type' => 'set_field', 'path' => '/attributes/wits', 'value' => 5]], 1)->assertOk();
        $over->assertJsonPath('validation.valid', false);
        foreach ((array) $over->json('balances') as $budget => $left) {
            $this->assertGreaterThanOrEqual(0, $left, $budget);
        }
        $this->assertSame(0, $this->character->refresh()->sheet_revision); // still only a draft
    }

    public function test_draft_versions_conflict_and_drafts_never_touch_the_official_sheet(): void
    {
        $this->draft(self::creationOps())->assertOk()->assertJsonPath('draft_version', 1);
        $this->draft(self::creationOps(), 0)->assertStatus(409)->assertJsonPath('code', 'draft.version_conflict')->assertJsonPath('current_revision', 1);
        $this->assertSame(0, $this->character->refresh()->sheet_revision);
        $this->assertSame([], (array) $this->character->sheet);

        $this->actingAs($this->player)->putJson($this->url('/draft'), ['draft_version' => 1, 'change_set' => ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0, 'operations' => [
            ['id' => 'x', 'type' => 'set_field', 'path' => '/attributes', 'value' => ['might' => 5]],
        ]]])->assertStatus(422)->assertJsonPath('code', 'change_set.invalid');
    }

    public function test_finalize_waits_for_the_character_lock(): void
    {
        $id = $this->submitted();
        $this->decide($id, 1, [
            ['operation_ids' => ['name'], 'decision' => 'approved'], ['operation_ids' => ['might', 'agility'], 'decision' => 'approved'],
            ['operation_ids' => ['climb'], 'decision' => 'approved'], ['operation_ids' => ['merit'], 'decision' => 'approved'],
        ])->assertOk();

        // The finalize path takes a row lock on the character; prove it by asking PostgreSQL.
        DB::listen(function ($query) use (&$locked): void {
            if (str_contains($query->sql, 'campaign_characters') && str_contains($query->sql, 'for update')) {
                $locked = true;
            }
        });
        $locked = false;
        $this->finalize($id, 2)->assertOk();
        $this->assertTrue($locked);
    }
}
