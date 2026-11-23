<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\SessionEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\Factory as Broadcaster;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers committed events to Reverb, in seq order, and marks them published. Failures leave
 * the event pending; the relay (novtt:events:relay) retries it. Clients deduplicate by seq.
 */
final class PublishSessionEvents implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const MAX_ATTEMPTS = 20;

    /** @param list<string> $eventIds */
    public function __construct(public readonly array $eventIds) {}

    public function handle(Broadcaster $broadcaster): void
    {
        $events = SessionEvent::query()->whereIn('id', $this->eventIds)->whereNull('published_at')->orderBy('session_id')->orderBy('seq')->get();
        self::deliver($broadcaster, $events->all());
    }

    /** @param list<SessionEvent> $events */
    public static function deliver(Broadcaster $broadcaster, array $events): int
    {
        $sent = 0;
        foreach ($events as $event) {
            try {
                $broadcaster->event(new SessionEventBroadcast($event));
                SessionEvent::query()->whereKey($event->getKey())->update(['published_at' => now(), 'attempts' => $event->attempts + 1]);
                $sent++;
            } catch (Throwable $e) {
                SessionEvent::query()->whereKey($event->getKey())->update(['attempts' => $event->attempts + 1]);
                // No payload in logs: it may contain private table content.
                Log::warning('session event delivery failed', ['event_id' => $event->getKey(), 'error' => $e::class]);
            }
        }

        return $sent;
    }
}
