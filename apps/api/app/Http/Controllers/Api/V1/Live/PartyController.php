<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\AuditTrail;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Audience;
use App\Live\Proximity;
use App\Live\SessionAccess;
use App\Live\SessionStream;
use App\Live\TableSnapshot;
use App\Models\CampaignCharacter;
use App\Models\GameSession;
use App\Models\ItemOffer;
use App\Models\LiveState;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The party in space (SPEC-MVP-003 NT-13/NT-14): the master groups characters into places (who
 * is near whom), and players hand items to nearby characters — offered by the giver, accepted by
 * the receiver, validated by the server (distance, quantity), undoable by the master.
 */
final class PartyController extends ApiController
{
    public function __construct(private readonly SessionAccess $access, private readonly SessionStream $stream, private readonly AuditTrail $audit) {}

    public function places(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $this->access->requireMaster($actor, $session->campaign_id);
        $data = $this->input($request, [
            'revision' => ['required', 'integer', 'min:0'],
            'places' => ['present', 'array', 'max:12'],
            'places.*.id' => ['required', 'string', 'regex:/^[a-z0-9-]{1,40}$/', 'distinct'],
            'places.*.name' => ['required', 'string', 'min:1', 'max:60'],
            'places.*.character_ids' => ['present', 'array', 'max:30'],
            'places.*.character_ids.*' => ['uuid'],
        ]);
        $characters = CampaignCharacter::query()->where('campaign_id', $session->campaign_id)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $seen = [];
        $places = [];
        foreach ($data['places'] as $place) {
            $ids = [];
            foreach ($place['character_ids'] as $id) {
                if (!in_array($id, $characters, true) || isset($seen[$id])) {
                    throw ApiProblem::conflict('places.character_invalid');
                }
                $seen[$id] = true;
                $ids[] = $id;
            }
            $places[] = ['id' => $place['id'], 'name' => $place['name'], 'character_ids' => $ids];
        }
        $value = DB::transaction(function () use ($sessionId, $data, $places): array {
            $session = $this->access->lockOpen($sessionId);
            $current = (int) ($session->places['revision'] ?? 0);
            if ($current !== (int) $data['revision']) {
                throw ApiProblem::conflict('places.revision_mismatch', $current);
            }
            $value = ['revision' => $current + 1, 'places' => $places];
            $session->update(['places' => $value]);
            $this->stream->append($session, 'places.updated', $value, Audience::table());

            return $value;
        });

        return new JsonResponse($value);
    }

    public function offer(Request $request, string $sessionId): JsonResponse
    {
        $actor = $this->actor($request);
        [$session] = $this->access->session($actor, $sessionId);
        $data = $this->input($request, [
            'from_character_id' => ['required', 'uuid'],
            'to_character_id' => ['required', 'uuid', 'different:from_character_id'],
            'item_id' => ['required', 'string', 'max:40'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $offer = DB::transaction(function () use ($actor, $sessionId, $data): ItemOffer {
            $session = $this->access->lockOpen($sessionId, requireActive: true);
            [$from, $to] = $this->pair($session, $data['from_character_id'], $data['to_character_id']);
            if (self::controller($from) !== $actor->getKey()) {
                throw new ApiProblem(403, 'item.not_yours');
            }
            $this->requireNear($session, $from, $to);
            $item = $this->item($from, $data['item_id']);
            if ($item === null || $item['qty'] < $data['qty']) {
                throw ApiProblem::conflict('item.not_enough');
            }
            $offer = ItemOffer::query()->create([
                'session_id' => $session->getKey(), 'campaign_id' => $session->campaign_id,
                'from_character_id' => $from->getKey(), 'to_character_id' => $to->getKey(),
                'item_id' => $item['id'], 'item_label' => $item['label'], 'qty' => $data['qty'], 'status' => 'pending', 'offered_by' => $actor->getKey(),
            ]);
            $this->announce($session, 'item_offer.created', $offer, $from, $to);

            return $offer;
        });

        return new JsonResponse(self::view($offer), 201);
    }

    public function accept(Request $request, string $offerId): JsonResponse
    {
        return $this->resolve($request, $offerId, 'accepted');
    }

    public function decline(Request $request, string $offerId): JsonResponse
    {
        return $this->resolve($request, $offerId, 'declined');
    }

    public function undo(Request $request, string $offerId): JsonResponse
    {
        return $this->resolve($request, $offerId, 'undone');
    }

    private function resolve(Request $request, string $offerId, string $outcome): JsonResponse
    {
        $actor = $this->actor($request);
        $found = Str::isUuid($offerId) ? ItemOffer::query()->find($offerId) : null;
        if ($found === null || $this->access->role($actor, $found->campaign_id) === null) {
            throw ApiProblem::notFound();
        }
        $offer = DB::transaction(function () use ($actor, $offerId, $outcome): ItemOffer {
            $offer = ItemOffer::query()->lockForUpdate()->findOrFail($offerId);
            $session = $this->access->lockOpen($offer->session_id);
            $from = CampaignCharacter::query()->findOrFail($offer->from_character_id);
            $to = CampaignCharacter::query()->findOrFail($offer->to_character_id);
            $isMaster = $this->access->role($actor, $offer->campaign_id) === CampaignRole::Master;
            if ($outcome === 'undone') {
                if (!$isMaster) {
                    throw new ApiProblem(403, 'live.master_only');
                }
                if ($offer->status !== 'accepted') {
                    throw ApiProblem::conflict('item_offer.not_accepted');
                }
                $this->move($session, $to, $from, $offer->item_label, $offer->qty, null);
            } else {
                if ($offer->status !== 'pending') {
                    throw ApiProblem::conflict('item_offer.resolved');
                }
                $receiver = self::controller($to) === $actor->getKey();
                $giver = self::controller($from) === $actor->getKey();
                // The receiver accepts or declines; the giver may withdraw (decline) their own offer.
                if (!($outcome === 'accepted' ? $receiver : ($receiver || $giver))) {
                    throw new ApiProblem(403, 'item.not_yours');
                }
                if ($outcome === 'accepted') {
                    $this->requireNear($session, $from, $to);
                    $this->move($session, $from, $to, $offer->item_label, $offer->qty, $offer->item_id);
                }
            }
            $offer->update(['status' => $outcome, 'resolved_by' => $actor->getKey(), 'resolved_at' => now()]);
            $this->audit->record($actor, $offer->campaign_id, "item_offer.{$outcome}", $offer, ['label' => $offer->item_label, 'qty' => $offer->qty]);
            $this->announce($session, 'item_offer.resolved', $offer, $from, $to);

            return $offer;
        });

        return new JsonResponse(self::view($offer));
    }

    /** Moves `qty` of an item between two characters' live states (by id at the giver, merged by label at the receiver). */
    private function move(GameSession $session, CampaignCharacter $from, CampaignCharacter $to, string $label, int $qty, ?string $itemId): void
    {
        $giver = LiveState::query()->where(['subject_kind' => 'character', 'subject_id' => $from->getKey()])->lockForUpdate()->first();
        $items = (array) ($giver?->items ?? []);
        $index = null;
        foreach ($items as $i => $item) {
            if (($itemId !== null && ($item['id'] ?? null) === $itemId) || ($itemId === null && mb_strtolower((string) $item['label']) === mb_strtolower($label))) {
                $index = $i;
            }
        }
        if ($giver === null || $index === null || (int) $items[$index]['qty'] < $qty) {
            throw ApiProblem::conflict('item.not_enough');
        }
        $items[$index]['qty'] = (int) $items[$index]['qty'] - $qty;
        $note = (string) ($items[$index]['note'] ?? '');
        if ($items[$index]['qty'] <= 0) {
            array_splice($items, $index, 1);
        }
        $giver->update(['items' => array_values($items), 'revision' => $giver->revision + 1]);

        $receiver = LiveState::query()->where(['subject_kind' => 'character', 'subject_id' => $to->getKey()])->lockForUpdate()->first()
            ?? LiveState::query()->create(['campaign_id' => $to->campaign_id, 'subject_kind' => 'character', 'subject_id' => $to->getKey(), 'resources' => [], 'conditions' => [], 'items' => [], 'revision' => 0]);
        $theirs = (array) ($receiver->items ?? []);
        $merged = false;
        foreach ($theirs as $i => $item) {
            if (mb_strtolower((string) $item['label']) === mb_strtolower($label)) {
                $theirs[$i]['qty'] = min(999, (int) $item['qty'] + $qty);
                $merged = true;
            }
        }
        if (!$merged) {
            if (count($theirs) >= 40) {
                throw ApiProblem::conflict('item.inventory_full');
            }
            $theirs[] = ['id' => 'i' . substr(str_replace('-', '', (string) Str::uuid7()), -12), 'label' => $label, 'qty' => $qty, 'note' => $note];
        }
        $receiver->update(['items' => array_values($theirs), 'revision' => $receiver->revision + 1]);
        foreach ([[$from, $giver], [$to, $receiver]] as [$character, $state]) {
            $this->stream->append($session, 'live.updated', ['subject' => ['kind' => 'character', 'id' => $character->getKey()], 'live' => TableSnapshot::live($state->refresh())], Audience::table());
        }
    }

    /** @return array{0: CampaignCharacter, 1: CampaignCharacter} */
    private function pair(GameSession $session, string $fromId, string $toId): array
    {
        $from = CampaignCharacter::query()->where('campaign_id', $session->campaign_id)->find($fromId);
        $to = CampaignCharacter::query()->where('campaign_id', $session->campaign_id)->find($toId);
        if ($from === null || $to === null) {
            throw ApiProblem::notFound();
        }

        return [$from, $to];
    }

    private function requireNear(GameSession $session, CampaignCharacter $a, CampaignCharacter $b): void
    {
        if (!Proximity::near($session->places, $a->getKey(), $b->getKey())) {
            throw ApiProblem::conflict('item.too_far');
        }
    }

    /** @return array{id: string, label: string, qty: int}|null */
    private function item(CampaignCharacter $character, string $itemId): ?array
    {
        $state = LiveState::query()->where(['subject_kind' => 'character', 'subject_id' => $character->getKey()])->first();
        foreach ((array) ($state?->items ?? []) as $item) {
            if (($item['id'] ?? null) === $itemId) {
                return ['id' => (string) $item['id'], 'label' => (string) $item['label'], 'qty' => (int) $item['qty']];
            }
        }

        return null;
    }

    private static function controller(CampaignCharacter $c): string
    {
        return (string) ($c->custodian_user_id ?? $c->player_user_id);
    }

    private function announce(GameSession $session, string $type, ItemOffer $offer, CampaignCharacter $from, CampaignCharacter $to): void
    {
        $audiences = [Audience::master()];
        foreach (array_unique([self::controller($from), self::controller($to)]) as $userId) {
            $audiences[] = Audience::user($userId);
        }
        $this->stream->append($session, $type, self::view($offer), ...$audiences);
    }

    /** @return array<string, mixed> */
    public static function view(ItemOffer $o): array
    {
        return [
            'id' => $o->getKey(), 'session_id' => $o->session_id, 'from_character_id' => $o->from_character_id, 'to_character_id' => $o->to_character_id,
            'item_id' => $o->item_id, 'item_label' => $o->item_label, 'qty' => $o->qty, 'status' => $o->status,
            'created_at' => $o->created_at?->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
