<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Demo\PresetDemoSeeder;
use App\Demo\TableDemoSeeder;
use App\Live\SheetCombination;
use App\Models\Campaign;
use App\Models\CampaignCharacter;
use App\Models\CampaignMember;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DrivesProposals;
use Tests\TestCase;

/** Demo campaigns on the authorial rules/2 presets and the guided-creation preview (SPEC-PRESET-ENGINE-001). */
final class PresetDemoTest extends TestCase
{
    use DrivesProposals;
    use RefreshDatabase;

    private function seedDemo(): Campaign
    {
        config(['novtt.demo_mode' => true]);
        $this->artisan('novtt:demo:seed')->assertSuccessful();

        return Campaign::query()->where('name', PresetDemoSeeder::CAMPAIGNS['presets/novtt-demo/demo-linhagens/1.0.0'][0])->sole();
    }

    public function test_each_demo_preset_gets_a_campaign_with_every_demo_player_and_no_characters(): void
    {
        $linhagens = $this->seedDemo();
        $heroico = Campaign::query()->where('name', PresetDemoSeeder::CAMPAIGNS['presets/novtt-demo/demo-heroico/1.0.0'][0])->sole();
        foreach ([$linhagens, $heroico] as $campaign) {
            $version = PresetVersion::query()->with('definition')->findOrFail($campaign->preset_version_id);
            $this->assertSame('published', $version->status->value);
            $this->assertSame('approved', $version->rights_status);
            $this->assertSame(1 + count(TableDemoSeeder::PLAYERS), CampaignMember::query()->where('campaign_id', $campaign->getKey())->count());
            $this->assertSame(0, CampaignCharacter::query()->where('campaign_id', $campaign->getKey())->count());
        }
        $this->assertSame('demo-linhagens', PresetVersion::query()->with('definition')->findOrFail($linhagens->preset_version_id)->definition->variant_slug);

        // Seeding again changes nothing.
        $this->artisan('novtt:demo:seed')->assertSuccessful();
        $this->assertSame(2, Campaign::query()->whereIn('name', array_column(PresetDemoSeeder::CAMPAIGNS, 0))->count());
    }

    public function test_the_draft_preview_carries_derived_values_creation_steps_and_choice_availability(): void
    {
        $campaign = $this->seedDemo();
        $player = User::query()->where('email', TableDemoSeeder::PLAYERS['player'][0])->sole();
        $created = $this->actingAs($player)->postJson("/api/v1/campaigns/{$campaign->getKey()}/characters", ['name' => 'Iara'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $character = CampaignCharacter::query()->findOrFail($created->json('id'));
        $this->assertFalse($created->json('creation.complete'));

        $set = static fn (string $id, string $path, mixed $value): array => ['id' => $id, 'type' => 'set_field', 'path' => $path, 'value' => $value];
        $response = $this->saveDraft($player, $character, [
            $set('n', '/identity/name', 'Iara'),
            $set('l', '/origin/lineage', 'aurora'),
            $set('p1', '/priorities/physical', 'primary'),
            $set('v', '/attributes/physical/vigor', 3),
        ])->assertOk();

        $response->assertJsonPath('preview.sheet.derived.vitality', 6);
        $response->assertJsonPath('balances.physical', 5);
        $steps = collect($response->json('preview.creation.steps'))->keyBy('id');
        $this->assertTrue($steps['identity']['complete']);
        $this->assertSame(['/origin/gift'], $steps['origin']['missing']);
        $gift = collect($response->json('preview.choices'))->firstWhere('id', 'gift');
        $this->assertSame(['voo_curto' => true, 'pele_de_pedra' => false, 'sussurro' => true], collect($gift['options'])->pluck('available', 'value')->all());
        $this->assertSame('aurora', collect($response->json('preview.choices'))->firstWhere('id', 'lineage')['value']);
        $this->assertArrayNotHasKey('derived', (array) $character->refresh()->sheet, 'previews are never stored');

        // The same preview comes back when the draft is reopened, and hidden fields stay out.
        $reopened = $this->actingAs($player)->getJson("/api/v1/campaign-characters/{$character->getKey()}/draft")->assertOk();
        $reopened->assertJsonPath('preview.sheet.derived.vitality', 6);
        $this->assertNull($reopened->json('preview.sheet.notes'));
    }

    public function test_roll_combinations_use_the_preset_default_for_values_never_written(): void
    {
        $campaign = $this->seedDemo();
        $components = app(SheetCombination::class)->resolve($campaign->preset_version_id, ['skills' => ['luta' => 2]], ['/attributes/physical/forca', '/skills/luta', '/skills/erudicao']);
        $this->assertSame([1, 2, 0], array_column($components, 'value'));
    }
}
