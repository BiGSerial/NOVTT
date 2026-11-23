<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\CampaignCharacter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** HTTP helpers for the draft -> proposal -> per-operation review -> finalize flow. */
trait DrivesProposals
{
    /** @param list<array<string, mixed>> $operations */
    protected function saveDraft(User $as, CampaignCharacter $character, array $operations): TestResponse
    {
        return $this->actingAs($as)->putJson("/api/v1/campaign-characters/{$character->getKey()}/draft", [
            'draft_version' => $this->draftVersion($as, $character),
            'change_set' => ['contract' => 'novtt.sheet-change-set/1', 'base_sheet_revision' => $character->refresh()->sheet_revision, 'operations' => $operations],
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function submitDraft(User $as, CampaignCharacter $character, array $extra = []): TestResponse
    {
        return $this->actingAs($as)->postJson("/api/v1/campaign-characters/{$character->getKey()}/change-requests", ['draft_version' => $this->draftVersion($as, $character)] + $extra, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    protected function draftVersion(User $as, CampaignCharacter $character): int
    {
        return (int) DB::table('character_drafts')->where(['campaign_character_id' => $character->getKey(), 'author_user_id' => $as->getKey()])->value('draft_version');
    }

    /** Drafts and submits; returns the change request id (asserting 201). @param list<array<string, mixed>> $operations */
    protected function propose(User $as, CampaignCharacter $character, array $operations): string
    {
        $this->saveDraft($as, $character, $operations)->assertOk();
        $response = $this->submitDraft($as, $character);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        return (string) $response->json('id');
    }

    /** One decision per operation; returns the decide response. @param list<string> $operationIds */
    protected function decideEach(User $master, string $requestId, array $operationIds, string $decision = 'approved'): TestResponse
    {
        $decisions = array_map(static fn (string $id): array => ['operation_ids' => [$id], 'decision' => $decision] + ($decision === 'rejected' ? ['reason' => 'Não cabe nesta campanha agora.'] : []), $operationIds);

        return $this->actingAs($master)->putJson("/api/v1/change-requests/{$requestId}/decisions", ['review_version' => 1, 'decisions' => $decisions]);
    }

    protected function finalizeRequest(User $master, string $requestId, int $reviewVersion = 2): TestResponse
    {
        return $this->actingAs($master)->postJson("/api/v1/change-requests/{$requestId}/finalize", ['review_version' => $reviewVersion], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** Proposes, approves every operation and finalizes (asserting success). @param list<array<string, mixed>> $operations */
    protected function applyAsProposal(User $player, User $master, CampaignCharacter $character, array $operations): void
    {
        $id = $this->propose($player, $character, $operations);
        $this->decideEach($master, $id, array_column($operations, 'id'))->assertOk();
        $done = $this->finalizeRequest($master, $id);
        $this->assertSame(200, $done->status(), (string) $done->getContent());
    }

    protected function grantBudget(User $master, CampaignCharacter $character, string $budget, int $amount): TestResponse
    {
        return $this->actingAs($master)->postJson("/api/v1/campaign-characters/{$character->getKey()}/grants", ['budget_id' => $budget, 'amount' => $amount, 'reason' => 'Fim de sessão'], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    protected function approveCharacter(User $master, CampaignCharacter $character): TestResponse
    {
        return $this->actingAs($master)->postJson("/api/v1/campaign-characters/{$character->getKey()}/approve", [], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> */
    protected static function op(string $id, string $path, mixed $value): array
    {
        return ['id' => $id, 'type' => 'set_field', 'path' => $path, 'value' => $value];
    }

    /** A complete, valid creation for demo-linhagens (mirrors the engine acceptance test). @return list<array<string, mixed>> */
    protected static function linhagensCreation(): array
    {
        $set = [
            'n' => ['/identity/name', 'Iara'], 'l' => ['/origin/lineage', 'aurora'], 'g' => ['/origin/gift', 'voo_curto'],
            'p1' => ['/priorities/physical', 'primary'], 'p2' => ['/priorities/social', 'secondary'], 'p3' => ['/priorities/mental', 'tertiary'],
            'a1' => ['/attributes/physical/forca', 3], 'a2' => ['/attributes/physical/destreza', 4], 'a3' => ['/attributes/physical/vigor', 3],
            'a4' => ['/attributes/social/carisma', 3], 'a5' => ['/attributes/social/manipulacao', 2], 'a6' => ['/attributes/social/aparencia', 3],
            'a7' => ['/attributes/mental/percepcao', 2], 'a8' => ['/attributes/mental/inteligencia', 2], 'a9' => ['/attributes/mental/raciocinio', 2],
            's1' => ['/skills/luta', 3], 's2' => ['/skills/furtividade', 2], 's3' => ['/skills/oficio', 1],
        ];

        return array_map(static fn (string $id, array $pv): array => self::op($id, $pv[0], $pv[1]), array_keys($set), $set);
    }

    /** A complete creation for demo-heroico: 12 ability points. @return list<array<string, mixed>> */
    protected static function heroicoCreation(): array
    {
        return [
            self::op('n', '/identity/name', 'Tauã'), self::op('v', '/calling/vocation', 'lamina'),
            self::op('f', '/abilities/forca', 13), self::op('a', '/abilities/agilidade', 13), self::op('m', '/abilities/mente', 10),
        ];
    }
}
