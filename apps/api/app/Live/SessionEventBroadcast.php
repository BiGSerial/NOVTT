<?php

declare(strict_types=1);

namespace App\Live;

use App\Models\SessionEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** One committed session event on the private channel of its audience. */
final class SessionEventBroadcast implements ShouldBroadcastNow
{
    public function __construct(private readonly SessionEvent $event) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $audience = match ($this->event->audience) {
            'table' => Audience::table(),
            'master' => Audience::master(),
            default => Audience::user((string) $this->event->audience_user_id),
        };

        return [new PrivateChannel($audience->channel($this->event->campaign_id))];
    }

    public function broadcastAs(): string
    {
        return 'session.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return SessionStream::envelope($this->event);
    }
}
