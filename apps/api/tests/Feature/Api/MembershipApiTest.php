<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\CampaignRole;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

final class MembershipApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    /** @param array<string, mixed> $body */
    private function invite(User $master, Campaign $campaign, array $body = ['expires_in_hours' => 24]): TestResponse
    {
        return $this->actingAs($master)->postJson("/api/v1/campaigns/{$campaign->getKey()}/invitations", $body);
    }

    private function accept(User $user, string $token): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/invitations/accept', ['token' => $token]);
    }

    public function test_master_invites_and_a_player_joins_once(): void
    {
        [$master, $player] = [$this->makeUser('Mestre'), $this->makeUser('Ana')];
        $campaign = $this->makeCampaign($master);

        $invitation = $this->invite($master, $campaign)->assertCreated();
        $token = (string) $invitation->json('token');
        $this->assertGreaterThanOrEqual(43, strlen($token));
        $this->assertDatabaseMissing('campaign_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('campaign_invitations', ['token_hash' => hash('sha256', $token)]);

        $this->accept($player, $token)->assertOk()->assertJsonPath('role', 'player')->assertJsonPath('display_name', 'Ana');
        $this->accept($player, $token)->assertOk(); // idempotent for an existing member
        $this->assertDatabaseHas('campaign_invitations', ['id' => $invitation->json('id'), 'uses' => 1]);

        $list = $this->actingAs($master)->getJson("/api/v1/campaigns/{$campaign->getKey()}/invitations")->assertOk();
        $this->assertArrayNotHasKey('token', $list->json('data.0'));
        $this->assertStringNotContainsString($token, (string) $list->getContent());
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR));

        $members = $this->actingAs($player)->getJson("/api/v1/campaigns/{$campaign->getKey()}/members")->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('@', (string) $members->getContent(), 'member list never exposes e-mails');
    }

    public function test_invalid_tokens_all_look_the_same(): void
    {
        [$master, $player] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $expired = (string) $this->invite($master, $campaign, ['expires_in_hours' => 1])->json('token');
        $revoked = $this->invite($master, $campaign)->json();
        $exhausted = (string) $this->invite($master, $campaign, ['expires_in_hours' => 24, 'max_uses' => 1])->json('token');
        $this->accept($this->makeUser(), $exhausted)->assertOk();
        $this->actingAs($master)->deleteJson("/api/v1/campaigns/{$campaign->getKey()}/invitations/{$revoked['id']}")->assertNoContent();
        $this->travel(2)->hours();

        foreach ([str_repeat('x', 43), $expired, (string) $revoked['token'], $exhausted] as $token) {
            $this->accept($player, $token)->assertNotFound()->assertJsonPath('code', 'invitation.not_found');
        }
        $this->assertDatabaseMissing('campaign_members', ['user_id' => $player->getKey()]);
    }

    public function test_archived_campaigns_cannot_be_joined(): void
    {
        [$master, $player] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $token = (string) $this->invite($master, $campaign)->json('token');
        $campaign->update(['status' => 'archived', 'archived_at' => now()]);

        $this->accept($player, $token)->assertNotFound();
    }

    public function test_players_and_outsiders_cannot_manage_invitations(): void
    {
        [$master, $player, $outsider] = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);

        $this->invite($player, $campaign)->assertNotFound();
        $this->invite($outsider, $campaign)->assertNotFound();
        $this->actingAs($player)->getJson("/api/v1/campaigns/{$campaign->getKey()}/invitations")->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/v1/campaigns/{$campaign->getKey()}/members")->assertNotFound();
        $this->invite($master, $campaign, ['expires_in_hours' => 24, 'role' => 'master'])->assertStatus(422);
        $this->invite($master, $campaign, ['expires_in_hours' => 10000])->assertStatus(422);
    }

    public function test_member_removal_rules(): void
    {
        [$master, $alice, $bob, $coMaster] = [$this->makeUser(), $this->makeUser(), $this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $aliceMember = $this->addMember($campaign, $alice);
        $bobMember = $this->addMember($campaign, $bob);
        $base = "/api/v1/campaigns/{$campaign->getKey()}/members";
        $masterMember = $campaign->members()->where('user_id', $master->getKey())->sole();

        $this->actingAs($alice)->deleteJson("{$base}/{$bobMember->getKey()}")->assertNotFound(); // players cannot remove others
        $this->actingAs($master)->deleteJson("{$base}/{$masterMember->getKey()}")->assertStatus(409)->assertJsonPath('code', 'member.last_master');

        $this->actingAs($master)->deleteJson("{$base}/{$bobMember->getKey()}")->assertNoContent();
        $this->actingAs($bob)->getJson("/api/v1/campaigns/{$campaign->getKey()}")->assertNotFound();
        $this->actingAs($alice)->deleteJson("{$base}/{$aliceMember->getKey()}")->assertNoContent(); // leaving

        $coMasterMember = $this->addMember($campaign, $coMaster, CampaignRole::Master);
        $this->actingAs($master)->deleteJson("{$base}/{$coMasterMember->getKey()}")->assertStatus(409)->assertJsonPath('code', 'member.cannot_remove_master');
        $this->actingAs($master)->deleteJson("{$base}/{$masterMember->getKey()}")->assertNoContent(); // another master remains
        $this->assertDatabaseHas('audit_events', ['action' => 'member.removed', 'subject_id' => $bobMember->getKey()]);
        $this->assertDatabaseHas('audit_events', ['action' => 'member.left', 'subject_id' => $aliceMember->getKey()]);
    }

    public function test_removed_player_can_rejoin_with_a_new_invitation(): void
    {
        [$master, $player] = [$this->makeUser(), $this->makeUser()];
        $campaign = $this->makeCampaign($master);
        $member = $this->addMember($campaign, $player);
        $this->actingAs($master)->deleteJson("/api/v1/campaigns/{$campaign->getKey()}/members/{$member->getKey()}")->assertNoContent();

        $token = (string) $this->invite($master, $campaign)->json('token');
        $this->accept($player, $token)->assertOk()->assertJsonPath('id', $member->getKey());
        $this->actingAs($player)->getJson("/api/v1/campaigns/{$campaign->getKey()}")->assertOk();
    }

    public function test_token_guessing_is_rate_limited(): void
    {
        RateLimiter::clear('novtt-invitations');
        $player = $this->makeUser();
        for ($i = 0; $i < 10; $i++) {
            $this->accept($player, str_repeat((string) $i, 43))->assertNotFound();
        }
        $this->accept($player, str_repeat('z', 43))->assertStatus(429)->assertJsonPath('code', 'rate_limit.exceeded');
    }
}
