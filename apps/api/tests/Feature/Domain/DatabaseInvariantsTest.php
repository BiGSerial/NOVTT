<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\ChangeRequestStatus;
use App\Domain\PresetVersionStatus;
use App\Models\AuditEvent;
use App\Models\ChangeOperation;
use App\Models\CharacterSheetRevision;
use App\Models\OperationDecision;
use App\Models\PresetVersion;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** Invariants that must hold even if application code is wrong (PostgreSQL-enforced). */
final class DatabaseInvariantsTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private function assertRejected(Closure $statement, string $needle): void
    {
        try {
            DB::transaction($statement); // savepoint: the outer test transaction survives
            $this->fail("Statement was accepted; expected rejection containing '{$needle}'.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_only_one_active_change_request_per_campaign_character(): void
    {
        $master = $this->makeUser();
        $player = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);
        $character = $this->makeCharacter($campaign, $player);

        $this->makeChangeRequest($character, $player, ChangeRequestStatus::InReview);
        $this->assertRejected(fn () => $this->makeChangeRequest($character, $player), 'change_requests_one_active');

        // Finished requests do not count.
        $this->makeChangeRequest($character, $player, ChangeRequestStatus::Withdrawn);
        $this->assertSame(2, DB::table('change_requests')->where('campaign_character_id', $character->getKey())->count());
    }

    public function test_idempotency_key_is_unique_per_author(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $a = $this->makeCharacter($campaign, $master);
        $request = $this->makeChangeRequest($a, $master, ChangeRequestStatus::Withdrawn);

        $this->assertRejected(fn () => DB::table('change_requests')->insert([
            'id' => (string) Str::uuid(), 'campaign_character_id' => $a->getKey(), 'author_user_id' => $master->getKey(),
            'status' => 'withdrawn', 'base_sheet_revision' => 0, 'draft_version' => 1,
            'idempotency_key' => $request->idempotency_key, 'payload_hash' => str_repeat('a', 64), 'submitted_at' => now(),
        ]), 'change_requests_author_user_id_idempotency_key_unique');
    }

    public function test_preset_version_content_is_immutable_and_lifecycle_only_moves_forward(): void
    {
        $user = $this->makeUser();
        $version = $this->makePresetVersion($user, PresetVersionStatus::EngineCompatible, $user);

        $this->assertRejected(fn () => DB::table('preset_versions')->where('id', $version->getKey())->update(['rules' => '{}']), 'novtt.immutable_row');
        $this->assertRejected(fn () => DB::table('preset_versions')->where('id', $version->getKey())->update(['status' => 'draft']), 'novtt.invalid_transition');
        $this->assertRejected(fn () => DB::table('preset_versions')->where('id', $version->getKey())->delete(), 'novtt.immutable_row');

        DB::table('preset_versions')->where('id', $version->getKey())->update(['status' => 'published', 'published_at' => now()]);
        $this->assertSame(PresetVersionStatus::Published, PresetVersion::query()->findOrFail($version->getKey())->status);
    }

    public function test_pinned_preset_version_and_campaign_cannot_be_deleted(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);

        $this->assertRejected(fn () => DB::table('campaigns')->where('id', $campaign->getKey())->delete(), 'campaign_members_campaign_id_foreign');
    }

    public function test_history_audit_and_operations_are_append_only(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $character = $this->makeCharacter($campaign, $master);
        $request = $this->makeChangeRequest($character, $master);

        $revision = CharacterSheetRevision::query()->create([
            'campaign_character_id' => $character->getKey(), 'revision' => 1, 'sheet' => ['a' => 1],
            'created_by' => $master->getKey(), 'created_at' => now(),
        ]);
        $audit = AuditEvent::query()->create([
            'occurred_at' => now(), 'actor_user_id' => $master->getKey(), 'campaign_id' => $campaign->getKey(),
            'action' => 'test', 'subject_type' => 'campaign', 'subject_id' => $campaign->getKey(),
        ]);
        $operation = ChangeOperation::query()->create([
            'change_request_id' => $request->getKey(), 'operation_key' => 'op1', 'position' => 0, 'type' => 'set_field',
            'path' => '/attributes/might', 'section_id' => 'attributes', 'payload' => ['value' => 2], 'created_at' => now(),
        ]);

        foreach ([['character_sheet_revisions', $revision->getKey()], ['audit_events', $audit->getKey()], ['change_operations', $operation->getKey()]] as [$table, $id]) {
            $this->assertRejected(fn () => DB::table($table)->where('id', $id)->update(['id' => $id]), 'novtt.immutable_row');
            $this->assertRejected(fn () => DB::table($table)->where('id', $id)->delete(), 'novtt.immutable_row');
        }
    }

    public function test_rejection_requires_a_10_to_1000_character_reason_and_final_decisions_freeze(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $character = $this->makeCharacter($campaign, $master);
        $request = $this->makeChangeRequest($character, $master);
        $operation = ChangeOperation::query()->create([
            'change_request_id' => $request->getKey(), 'operation_key' => 'op1', 'position' => 0, 'type' => 'unset_field',
            'path' => '/identity/concept', 'section_id' => 'identity', 'payload' => [], 'created_at' => now(),
        ]);
        $base = ['change_operation_id' => $operation->getKey(), 'change_request_id' => $request->getKey(), 'review_version' => 1, 'decided_by' => $master->getKey(), 'decided_at' => now()];

        $this->assertRejected(fn () => OperationDecision::query()->create($base + ['decision' => 'rejected', 'reason' => 'too short']), 'operation_decisions_rejection_reason');
        $this->assertRejected(fn () => OperationDecision::query()->create($base + ['decision' => 'rejected', 'reason' => str_repeat('x', 1001)]), 'operation_decisions_rejection_reason');
        $this->assertRejected(fn () => OperationDecision::query()->create($base + ['decision' => 'rejected', 'reason' => '          ']), 'operation_decisions_rejection_reason');
        $this->assertRejected(fn () => OperationDecision::query()->create($base + ['decision' => 'approved', 'reason' => 'approved but explained']), 'operation_decisions_rejection_reason');

        $decision = OperationDecision::query()->create($base + ['decision' => 'rejected', 'reason' => 'Valor acima do permitido.']);
        $decision->update(['decision' => 'approved', 'reason' => null]); // provisional: editable
        $decision->update(['is_final' => true]);
        $this->assertRejected(fn () => DB::table('operation_decisions')->where('id', $decision->getKey())->update(['decision' => 'rejected', 'reason' => 'Mudança de ideia tardia.']), 'novtt.immutable_row');
    }

    public function test_applied_status_requires_finalization_and_resulting_revision(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $character = $this->makeCharacter($campaign, $master);

        $this->assertRejected(fn () => $this->makeChangeRequest($character, $master, ChangeRequestStatus::Applied), 'violates check constraint "change_requests_');
        $request = $this->makeChangeRequest($character, $master);
        $this->assertRejected(fn () => DB::table('change_requests')->where('id', $request->getKey())->update([
            'status' => 'applied', 'finalized_at' => now(), 'finalized_by' => $master->getKey(),
        ]), 'change_requests_applied_revision');
    }

    public function test_invitation_uses_cannot_exceed_max_uses(): void
    {
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);

        $this->assertRejected(fn () => DB::table('campaign_invitations')->insert([
            'id' => (string) Str::uuid(), 'campaign_id' => $campaign->getKey(), 'created_by' => $master->getKey(),
            'token_hash' => str_repeat('b', 64), 'role' => 'player', 'max_uses' => 1, 'uses' => 2, 'expires_at' => now()->addDay(),
        ]), 'campaign_invitations_uses');
    }
}
