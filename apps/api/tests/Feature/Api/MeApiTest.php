<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsDomain;
use Tests\TestCase;

/** The person's own space: dice preferences, characters everywhere, fellow players and presence. */
final class MeApiTest extends TestCase
{
    use BuildsDomain;
    use RefreshDatabase;

    private const SET = ['id' => 'brasa', 'name' => 'Brasa', 'body' => '#7a1f12', 'ink' => '#fde68a', 'finish' => 'gloss', 'texture' => ['pattern' => 'marble', 'accent' => '#f59e0b', 'scale' => 1.5]];

    public function test_dice_sets_with_textures_are_saved_validated_and_read_back(): void
    {
        $ana = $this->makeUser('Ana');
        $this->actingAs($ana)->getJson('/api/v1/me/preferences')->assertOk()->assertJsonPath('dice_sets', [])->assertJsonPath('active_dice_set', null);
        $this->actingAs($ana)->putJson('/api/v1/me/preferences', ['dice_sets' => [self::SET], 'active_dice_set' => 'brasa'])->assertOk()
            ->assertJsonPath('dice_sets.0.texture.pattern', 'marble')->assertJsonPath('active_dice_set', 'brasa');
        $this->actingAs($ana)->getJson('/api/v1/me/preferences')->assertJsonPath('dice_sets.0.name', 'Brasa');

        $this->actingAs($ana)->putJson('/api/v1/me/preferences', ['dice_sets' => [['texture' => ['pattern' => 'photo', 'accent' => '#ffffff']] + self::SET], 'active_dice_set' => null])->assertStatus(422);
        $this->actingAs($ana)->putJson('/api/v1/me/preferences', ['dice_sets' => [['body' => 'red'] + self::SET], 'active_dice_set' => null])->assertStatus(422);
        $this->actingAs($ana)->putJson('/api/v1/me/preferences', ['dice_sets' => array_fill(0, 13, self::SET), 'active_dice_set' => null])->assertStatus(422);
        $this->actingAs($ana)->putJson('/api/v1/me/preferences', ['dice_sets' => [self::SET], 'active_dice_set' => 'ghost'])->assertOk()->assertJsonPath('active_dice_set', null);
    }

    public function test_my_characters_list_every_campaign_and_flags_an_outdated_sheet(): void
    {
        $master = $this->makeUser('Mestre');
        $ana = $this->makeUser('Ana');
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $ana);
        $hero = $this->makeCharacter($campaign, $ana);
        $this->makeCharacter($campaign, $master);

        $list = $this->actingAs($ana)->getJson('/api/v1/me/characters')->assertOk()->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.id', $hero->getKey())->assertJsonPath('data.0.campaign.name', 'Campaign')->assertJsonPath('data.0.outdated', false);

        $campaign->update(['preset_version_id' => $this->makePresetVersion($master, version: '1.1.0')->getKey()]);
        $this->actingAs($ana)->getJson('/api/v1/me/characters')->assertJsonPath('data.0.outdated', true);
    }

    public function test_companions_are_fellow_members_only_with_coarse_presence(): void
    {
        $master = $this->makeUser('Mestre');
        $ana = $this->makeUser('Ana');
        $bruno = $this->makeUser('Bruno');
        $stranger = $this->makeUser('Estranho');
        $campaign = $this->makeCampaign($master);
        $this->addMember($campaign, $ana);
        $this->addMember($campaign, $bruno);
        $this->makeCampaign($stranger);

        $this->actingAs($bruno)->getJson('/api/v1/me')->assertOk(); // marks Bruno as seen
        self::assertNotNull(DB::table('users')->where('id', $bruno->getKey())->value('last_seen_at'));
        DB::table('users')->where('id', $master->getKey())->update(['last_seen_at' => now()->subDays(2)]);

        $people = collect($this->actingAs($ana)->getJson('/api/v1/me/companions')->assertOk()->json('data'))->keyBy('name');
        self::assertSame(['Bruno', 'Mestre'], $people->keys()->all(), 'online first; strangers never listed');
        self::assertTrue($people['Bruno']['online']);
        self::assertFalse($people['Mestre']['online']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $people['Mestre']['last_seen_on']);
        self::assertSame('master', $people['Mestre']['campaigns'][0]['role']);
        self::assertArrayNotHasKey('email', $people['Bruno']);
    }
}
