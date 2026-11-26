<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Live\Audience;
use App\Live\SessionEventBroadcast;
use App\Models\SessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** Private channel authorization (SPEC-MVP-002 LT-03; acceptance 6). */
final class RealtimeChannelAuthTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pusher-protocol signing is local: no server is contacted.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test', 'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]],
        ]);
        // Channels were registered on the boot-time (null) broadcaster; register them on this one.
        require base_path('routes/channels.php');
    }

    private function auth(string $channel): TestResponse
    {
        return $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
    }

    public function test_channels_follow_membership_and_role(): void
    {
        $master = $this->makeUser();
        $player = $this->makeUser();
        $stranger = $this->makeUser();
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $player);
        $id = $campaign->getKey();

        $this->actingAs($player)->auth("campaign.{$id}")->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($player)->auth("campaign.{$id}.master")->assertForbidden();
        $this->actingAs($master)->auth("campaign.{$id}.master")->assertOk();
        $this->actingAs($stranger)->auth("campaign.{$id}")->assertForbidden();
        $this->actingAs($player)->auth("user.{$player->getKey()}")->assertOk();
        $this->actingAs($player)->auth("user.{$master->getKey()}")->assertForbidden();
        $this->actingAs($master)->auth("workspace.{$master->getKey()}")->assertOk();
        $this->actingAs($player)->auth("workspace.{$master->getKey()}")->assertForbidden();
        $this->actingAs($player)->auth('campaign.not-a-uuid')->assertForbidden();
    }

    public function test_unauthenticated_clients_cannot_subscribe(): void
    {
        $this->auth('campaign.'.'00000000-0000-0000-0000-000000000000')->assertUnauthorized();
    }

    public function test_each_audience_maps_to_its_own_private_channel(): void
    {
        self::assertSame('campaign.c', Audience::table()->channel('c'));
        self::assertSame('campaign.c.master', Audience::master()->channel('c'));
        self::assertSame('user.u', Audience::user('u')->channel('c'));
        $event = new SessionEvent(['campaign_id' => 'c', 'audience' => 'user', 'audience_user_id' => 'u']);
        self::assertSame('private-user.u', (new SessionEventBroadcast($event))->broadcastOn()[0]->name);
    }
}
