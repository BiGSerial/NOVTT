<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomain;
use Tests\Support\DrivesProposals;
use Tests\TestCase;

/**
 * Preset engine Sprint 001 test 3: two attempts to apply the same XP purchase never debit twice.
 * Committed data and a second PostgreSQL connection make the row lock real.
 */
final class ExperiencePurchaseConcurrencyTest extends TestCase
{
    use BuildsDomain;
    use DatabaseTruncation;
    use DrivesProposals;

    protected function tearDown(): void
    {
        DB::statement('SET lock_timeout = 0');
        DB::connection('pgsql_other')->disconnect();
        parent::tearDown();
    }

    public function test_a_purchase_waits_for_the_character_lock_and_debits_exactly_once(): void
    {
        config(['database.connections.pgsql_other' => config('database.connections.pgsql')]);
        [$team, $master, $player] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master, $this->publishDemoPreset($team, 'demo-linhagens'));
        $this->addMember($campaign, $player);
        $character = $this->makeCharacter($campaign, $player);
        $this->applyAsProposal($player, $master, $character, self::linhagensCreation());
        $this->approveCharacter($master, $character)->assertOk();
        $this->grantBudget($master, $character, 'xp', 12)->assertCreated();
        $id = $this->propose($player, $character, [self::op('up', '/attributes/physical/forca', 4)]);
        $this->decideEach($master, $id, ['up'])->assertOk();
        $revision = (int) $character->refresh()->sheet_revision;

        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM campaign_characters WHERE id = ? FOR UPDATE', [$character->getKey()]);
        DB::statement("SET lock_timeout = '300ms'");
        $this->assertSame(500, $this->finalizeRequest($master, $id)->status(), 'lock wait timed out');
        $this->assertSame($revision, (int) DB::table('campaign_characters')->where('id', $character->getKey())->value('sheet_revision'), 'nothing applied while locked');
        $other->rollBack();
        DB::statement('SET lock_timeout = 0');

        $this->finalizeRequest($master, $id)->assertOk()->assertJsonPath('status', 'applied');
        $this->finalizeRequest($master, $id)->assertStatus(409);

        $ledger = array_filter((array) $character->refresh()->sheet['_ledger'], static fn (array $e): bool => $e['budget'] === 'xp');
        $this->assertCount(1, $ledger);
        $this->assertSame(12, array_sum(array_column($ledger, 'cost')));
        $this->assertSame($revision + 1, (int) $character->sheet_revision);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'change_request.applied')->where('subject_id', $id)->count());
    }
}
