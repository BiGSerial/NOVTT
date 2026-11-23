<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\CampaignRole;
use App\Models\PairingCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** SPEC-MVP-002 LT-01 (accounts), LT-07 (Roteiro, Atlas) and LT-08 (paired devices). */
final class AccountPairingWorldApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** First-party origin so the API starts a session (ADR 0009). */
    private const SPA = ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];

    public function test_register_login_logout(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Ana', 'email' => 'Ana@Example.test', 'password' => 'correct horse battery'], self::SPA)->assertCreated()->assertJsonPath('name', 'Ana');
        $this->getJson('/api/v1/me', self::SPA)->assertOk();
        $this->postJson('/api/v1/auth/logout', [], self::SPA)->assertNoContent();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.test', 'password' => 'wrong password!!'], self::SPA)->assertUnauthorized()->assertJsonPath('code', 'auth.invalid_credentials');
        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.test', 'password' => 'correct horse battery'], self::SPA)->assertOk();
        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'ana@example.test', 'password' => 'another password'], self::SPA)->assertUnprocessable();
        $this->postJson('/api/v1/auth/register', ['name' => 'Y', 'email' => 'y@example.test', 'password' => 'short'], self::SPA)->assertUnprocessable();
    }

    public function test_pairing_requires_master_confirmation_and_revocation_ends_the_session(): void
    {
        $master = $this->makeUser('Mestre');
        $pairing = $this->postJson('/api/v1/pairing', ['device_label' => 'Tablet'], self::SPA)->assertCreated();
        $code = $pairing->json('code');
        $url = "/api/v1/pairing/{$pairing->json('pairing_id')}/claim";
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertSame(0, PairingCode::query()->where('code_hash', $code)->count(), 'codes are stored hashed');

        $this->postJson($url, ['claim_secret' => $pairing->json('claim_secret')], self::SPA)->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->postJson($url, ['claim_secret' => str_repeat('x', 43)], self::SPA)->assertStatus(410);
        $this->actingAs($master)->postJson('/api/v1/pairing/confirm', ['code' => $code === '000000' ? '000001' : '000000'])->assertUnprocessable();
        $this->actingAs($master)->postJson('/api/v1/pairing/confirm', ['code' => $code])->assertOk();
        $this->actingAs($master)->postJson('/api/v1/pairing/confirm', ['code' => $code])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $claimed = $this->postJson($url, ['claim_secret' => $pairing->json('claim_secret')], self::SPA)->assertOk()->assertJsonPath('status', 'claimed');
        $deviceId = $claimed->json('me.paired_device_id');
        self::assertNotNull($deviceId);
        $this->postJson($url, ['claim_secret' => $pairing->json('claim_secret')], self::SPA)->assertStatus(410);
        // The paired session works, but cannot manage devices or pair others.
        $this->getJson('/api/v1/me', self::SPA)->assertOk()->assertJsonPath('id', $master->getKey());
        $this->getJson('/api/v1/devices', self::SPA)->assertForbidden();

        $this->withSession([])->actingAs($master)->deleteJson("/api/v1/devices/{$deviceId}")->assertNoContent();
        $this->withSession(['paired_device_id' => $deviceId])->actingAs($master)->getJson('/api/v1/me', self::SPA)->assertUnauthorized()->assertJsonPath('code', 'device.revoked');
    }

    public function test_expired_codes_cannot_be_confirmed(): void
    {
        $master = $this->makeUser();
        $code = $this->postJson('/api/v1/pairing', ['device_label' => 'Tablet'], self::SPA)->json('code');
        $this->travel(3)->minutes();
        $this->actingAs($master)->postJson('/api/v1/pairing/confirm', ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'pairing.code_invalid');
    }

    public function test_roteiro_and_atlas_are_master_only_with_public_summaries(): void
    {
        $master = $this->makeUser('Mestre');
        $player = $this->makeUser('Ana');
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player, CampaignRole::Player);
        $base = "/api/v1/campaigns/{$campaign->getKey()}";

        $roteiro = $this->actingAs($master)->postJson("{$base}/roteiros", ['title' => 'Sessão 2', 'content' => [
            'strong_start' => 'A ponte desaba.',
            'scenes' => [['title' => 'A ponte', 'goal' => 'Atravessar', 'clues' => [['title' => 'Corda cortada', 'body' => 'Alguém sabotou.']]]],
            'clocks' => [['label' => 'Cheia do rio', 'segments' => 6]],
        ]])->assertCreated();
        $this->actingAs($player)->getJson("{$base}/roteiros")->assertForbidden();
        $this->actingAs($master)->putJson("/api/v1/roteiros/{$roteiro->json('id')}", ['revision' => 2, 'title' => 'x'])->assertConflict();

        // Starting a session from the roteiro seeds the compass and the clues.
        $session = $this->actingAs($master)->postJson("{$base}/sessions", ['title' => 'Sessão 2', 'roteiro_id' => $roteiro->json('id')], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $session->assertJsonPath('compass.scene', 'A ponte')->assertJsonPath('compass.clocks.0.segments', 6);
        $this->actingAs($master)->getJson("/api/v1/sessions/{$session->json('id')}/snapshot")->assertJsonCount(1, 'clues');

        $secret = $this->actingAs($master)->postJson("{$base}/atlas", ['kind' => 'secret', 'title' => 'O barqueiro é o traidor', 'known_to_players' => true])->assertCreated()->assertJsonPath('known_to_players', false);
        $place = $this->actingAs($master)->postJson("{$base}/atlas", ['kind' => 'place', 'title' => 'Vau Cinzento', 'summary' => 'Aldeia ribeirinha', 'details' => 'Contrabando', 'known_to_players' => true, 'links' => [$secret->json('id')]])->assertCreated();
        $this->actingAs($master)->postJson("/api/v1/atlas/{$place->json('id')}/timeline", ['note' => 'Ponte destruída'])->assertCreated()->assertJsonPath('session_id', $session->json('id'));

        $players = $this->actingAs($player)->getJson("{$base}/atlas")->assertOk()->assertJsonCount(1, 'data');
        $players->assertJsonPath('data.0.title', 'Vau Cinzento')->assertJsonMissingPath('data.0.details')->assertJsonMissingPath('data.0.timeline');
        $this->actingAs($player)->postJson("{$base}/atlas", ['kind' => 'place', 'title' => 'x'])->assertForbidden();
        $this->actingAs($master)->getJson("{$base}/atlas")->assertJsonCount(2, 'data');
        $this->actingAs($this->makeUser())->getJson("{$base}/atlas")->assertNotFound();
    }
}
