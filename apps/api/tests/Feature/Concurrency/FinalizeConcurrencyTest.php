<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/**
 * Uses committed data and a second PostgreSQL connection, so row locks are real (RefreshDatabase
 * would hide the data from other connections inside its wrapping transaction).
 */
final class FinalizeConcurrencyTest extends TestCase
{
    use BuildsDomain;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('SET lock_timeout = 0');
        DB::connection('pgsql_other')->disconnect();
        parent::tearDown();
    }

    public function test_finalize_serializes_on_the_character_row_and_applies_once(): void
    {
        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
        $master = $this->makeUser();
        $player = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);
        $character = $this->makeCharacter($campaign, $player);
        $base = "/api/v1/campaign-characters/{$character->getKey()}";

        $this->actingAs($player)->putJson("{$base}/draft", ['draft_version' => 0, 'change_set' => [
            'contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => 0,
            'operations' => [['id' => 'n', 'type' => 'set_field', 'path' => '/identity/name', 'value' => 'Ana']],
        ]])->assertOk();
        $id = (string) $this->actingAs($player)->postJson("{$base}/change-requests", ['draft_version' => 1], ['Idempotency-Key' => (string) Str::uuid()])->json('id');
        $this->actingAs($master)->putJson("/api/v1/change-requests/{$id}/decisions", ['review_version' => 1, 'decisions' => [['operation_ids' => ['n'], 'decision' => 'approved']]])->assertOk();

        // Another transaction holds the character row: finalize must wait, not write around it.
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM campaign_characters WHERE id = ? FOR UPDATE', [$character->getKey()]);
        DB::statement("SET lock_timeout = '300ms'");

        $blocked = $this->actingAs($master)->postJson("/api/v1/change-requests/{$id}/finalize", ['review_version' => 2], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertSame(500, $blocked->status(), 'lock wait timed out');
        $this->assertSame(0, (int) DB::table('campaign_characters')->where('id', $character->getKey())->value('sheet_revision'));
        $this->assertSame(0, DB::table('character_sheet_revisions')->count());
        $this->assertSame(0, DB::table('idempotency_records')->where('scope', 'like', 'change_request.finalize:%')->count(), 'no outcome stored for a failed attempt');

        $other->rollBack();
        $key = (string) Str::uuid();
        $this->actingAs($master)->postJson("/api/v1/change-requests/{$id}/finalize", ['review_version' => 2], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('resulting_sheet_revision', 1);
        // A second, independent finalize sees the committed state and is refused.
        $this->actingAs($master)->postJson("/api/v1/change-requests/{$id}/finalize", ['review_version' => 2], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(1, DB::table('character_sheet_revisions')->count());
    }

    public function test_concurrent_submissions_cannot_create_two_active_proposals(): void
    {
        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
        $master = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $character = $this->makeCharacter($campaign, $master);
        $insert = static fn (string $connection): bool => DB::connection($connection)->table('change_requests')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'campaign_character_id' => $character->getKey(), 'author_user_id' => $master->getKey(),
            'status' => 'submitted', 'base_sheet_revision' => 0, 'draft_version' => 1, 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'submitted_at' => now(),
        ]) === 1;

        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $this->assertTrue($insert('pgsql_other'));
        DB::statement("SET lock_timeout = '300ms'");
        // The partial unique index makes the second writer wait for the first to finish ...
        try {
            $insert('pgsql');
            $this->fail('second insert should wait on the unique index');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('lock timeout', $e->getMessage());
        }
        $other->commit();
        // ... and then lose.
        $this->assertFalse($insert('pgsql'));
        $this->assertSame(1, DB::table('change_requests')->whereIn('status', ['submitted', 'in_review', 'awaiting_resolution'])->count());
    }
}
