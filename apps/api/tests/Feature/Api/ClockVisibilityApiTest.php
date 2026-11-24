<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-003 NT-11: clocks carry a tone and say who sees them; players get only their share. */
final class ClockVisibilityApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private User $master;

    private User $ana;

    private User $bruno;

    private Campaign $campaign;

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
        $this->sessionId = $this->actingAs($this->master)->postJson("/api/v1/campaigns/{$this->campaign->getKey()}/sessions", ['title' => 'Sessão'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('id');
    }

    /** @param list<array<string, mixed>> $clocks */
    private function setClocks(array $clocks, int $revision = 1): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->master)->putJson("/api/v1/sessions/{$this->sessionId}/compass", [
            'revision' => $revision,
            'compass' => ['scene' => 'Ponte', 'objective' => 'Atravessar', 'next_clue' => 'Pena', 'spotlight' => [], 'clocks' => $clocks],
        ]);
    }

    private function clock(string $id, string $visibility, array $players = [], string $tone = 'threat'): array
    {
        return ['id' => $id, 'label' => "Relógio {$id}", 'segments' => 6, 'filled' => 2, 'tone' => $tone, 'visibility' => $visibility, 'player_ids' => $players];
    }

    /** @return list<array<string, mixed>> */
    private function snapshotClocks(User $who): array
    {
        return $this->actingAs($who)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk()->json('session.clocks');
    }

    public function test_players_see_table_clocks_and_their_own_only(): void
    {
        $this->setClocks([
            $this->clock('secret', 'master'),
            $this->clock('all', 'table', [], 'progress'),
            $this->clock('ana', 'players', [$this->ana->getKey()], 'mystery'),
        ])->assertOk()->assertJsonPath('compass.clocks.2.player_ids.0', $this->ana->getKey());

        $ana = $this->snapshotClocks($this->ana);
        self::assertSame(['all', 'ana'], array_column($ana, 'id'));
        self::assertSame('mystery', $ana[1]['tone']);
        self::assertArrayNotHasKey('player_ids', $ana[1], 'players never see who else a clock is shown to');
        self::assertArrayNotHasKey('visibility', $ana[1]);
        self::assertSame(['all'], array_column($this->snapshotClocks($this->bruno), 'id'));

        $master = $this->actingAs($this->master)->getJson("/api/v1/sessions/{$this->sessionId}/snapshot")->assertOk();
        $master->assertJsonCount(3, 'session.compass.clocks')->assertJsonMissingPath('session.clocks');
    }

    public function test_players_hear_clock_changes_only_when_their_view_changes(): void
    {
        $this->setClocks([$this->clock('ana', 'players', [$this->ana->getKey()])])->assertOk();
        $this->setClocks([$this->clock('ana', 'players', [$this->ana->getKey()]), $this->clock('secret', 'master')], 2)->assertOk();
        $this->setClocks([], 3)->assertOk();

        $anaEvents = collect($this->actingAs($this->ana)->getJson("/api/v1/sessions/{$this->sessionId}/events?after=0")->assertOk()->json('data'))->where('type', 'clocks.updated')->values();
        self::assertCount(2, $anaEvents, 'shown, then hidden; the secret clock changed nothing for Ana');
        self::assertSame('ana', $anaEvents[0]['payload']['clocks'][0]['id']);
        self::assertSame([], $anaEvents[1]['payload']['clocks']);

        $brunoEvents = collect($this->actingAs($this->bruno)->getJson("/api/v1/sessions/{$this->sessionId}/events?after=0")->assertOk()->json('data'))->where('type', 'clocks.updated');
        self::assertCount(0, $brunoEvents);
    }

    public function test_unknown_users_are_dropped_and_old_clocks_default_to_master_only(): void
    {
        $stranger = $this->makeUser('Estranho');
        $this->setClocks([
            ['id' => 'old', 'label' => 'Sem campos novos', 'segments' => 4, 'filled' => 0],
            $this->clock('x', 'players', [$stranger->getKey(), $this->master->getKey(), $this->bruno->getKey()]),
        ])->assertOk()
            ->assertJsonPath('compass.clocks.0.visibility', 'master')->assertJsonPath('compass.clocks.0.tone', 'threat')
            ->assertJsonPath('compass.clocks.1.player_ids', [$this->bruno->getKey()]);
        self::assertSame(['x'], array_column($this->snapshotClocks($this->bruno), 'id'));
        self::assertSame([], $this->snapshotClocks($this->ana));
    }

    public function test_invalid_tone_or_visibility_is_rejected_and_players_cannot_set_clocks(): void
    {
        $this->setClocks([$this->clock('a', 'everyone')])->assertStatus(422);
        $this->setClocks([$this->clock('a', 'table', [], 'neon')])->assertStatus(422);
        $this->setClocks([$this->clock('a', 'players', ['not-a-uuid'])])->assertStatus(422);
        $this->actingAs($this->ana)->putJson("/api/v1/sessions/{$this->sessionId}/compass", [
            'revision' => 1, 'compass' => ['scene' => 'Ponte', 'objective' => 'Atravessar', 'next_clue' => 'Pena', 'spotlight' => [], 'clocks' => [$this->clock('a', 'table')]],
        ])->assertForbidden();
    }
}
