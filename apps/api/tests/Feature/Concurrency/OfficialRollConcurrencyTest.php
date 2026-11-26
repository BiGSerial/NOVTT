<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-003 acceptance 6: concurrent submissions cannot consume the same remaining attempt. */
final class OfficialRollConcurrencyTest extends TestCase
{
    use BuildsDomain;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('SET lock_timeout = 0');
        DB::connection('pgsql_other')->disconnect();
        parent::tearDown();
    }

    public function test_a_roll_waits_for_the_locked_session_and_cannot_overspend(): void
    {
        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
        $master = $this->makeUser();
        $player = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);
        $character = $this->makeCharacter($campaign, $player);
        $session = $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/sessions", ['title' => 'S'], ['Idempotency-Key' => (string) Str::uuid()])->json('id');
        $request = $this->actingAs($master)->postJson("/api/v1/sessions/{$session}/roll-requests", [
            'campaign_character_id' => $character->getKey(), 'paths' => ['/attributes/agility'],
            'dice' => ['faces' => 10, 'count_mode' => 'pool', 'reading' => 'successes'],
        ])->assertCreated()->json('id');

        // Another transaction (e.g. a concurrent roll) holds the session: this one must wait.
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM game_sessions WHERE id = ? FOR UPDATE', [$session]);
        DB::statement("SET lock_timeout = '300ms'");
        $blocked = $this->actingAs($player)->postJson("/api/v1/roll-requests/{$request}/rolls", ['expected_revision' => 0], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertSame(500, $blocked->status(), 'lock wait timed out');
        $this->assertSame(0, DB::table('official_rolls')->count());
        $other->rollBack();
        DB::statement('SET lock_timeout = 0');

        $this->actingAs($player)->postJson("/api/v1/roll-requests/{$request}/rolls", ['expected_revision' => 0], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        // A second submission racing on the same revision finds it consumed.
        $this->actingAs($player)->postJson("/api/v1/roll-requests/{$request}/rolls", ['expected_revision' => 0], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, DB::table('official_rolls')->count());
        $this->assertSame(1, (int) DB::table('roll_requests')->where('id', $request)->value('attempts_consumed'));
    }
}
