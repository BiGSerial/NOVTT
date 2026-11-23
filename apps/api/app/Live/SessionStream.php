<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\GameSession;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Appends authoritative session events (SPEC-MVP-002 LT-03). Callers MUST hold the session row
 * lock (GameSession::lockForUpdate) inside the transaction of the change, so `seq` is strictly
 * increasing and gap-free per session. Broadcast happens only after commit.
 */
final class SessionStream
{
    /**
     * @param  array<string, mixed>  $payload  never contains credentials or tokens
     * @return list<SessionEvent>
     */
    public function append(GameSession $session, string $type, array $payload, Audience ...$audiences): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Session events must be appended inside a transaction.');
        }
        $events = [];
        foreach ($this->unique($audiences) as $audience) {
            $session->last_seq = $session->last_seq + 1;
            $events[] = SessionEvent::query()->create([
                'session_id' => $session->getKey(),
                'campaign_id' => $session->campaign_id,
                'seq' => $session->last_seq,
                'audience' => $audience->kind,
                'audience_user_id' => $audience->userId,
                'type' => $type,
                'payload' => $payload,
                'occurred_at' => now(),
            ]);
        }
        $session->save();
        $ids = array_map(static fn (SessionEvent $e): string => $e->getKey(), $events);
        DB::afterCommit(static fn () => PublishSessionEvents::dispatch($ids));

        return $events;
    }

    /**
     * @param  array<Audience>  $audiences
     * @return list<Audience>
     */
    private function unique(array $audiences): array
    {
        $seen = [];
        foreach ($audiences as $a) {
            $seen[$a->kind.':'.$a->userId] = $a;
        }

        return array_values($seen);
    }

    /** Wire format shared by the realtime message and the recovery endpoint. */
    public static function envelope(SessionEvent $event): array
    {
        return [
            'contract' => 'novtt.session-event/1',
            'session_id' => $event->session_id,
            'seq' => $event->seq,
            'type' => $event->type,
            'occurred_at' => $event->occurred_at->format(DATE_RFC3339_EXTENDED),
            'payload' => (object) $event->payload,
        ];
    }
}
