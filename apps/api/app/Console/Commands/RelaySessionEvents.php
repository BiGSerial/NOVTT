<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\PublishSessionEvents;
use App\Models\SessionEvent;
use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\Factory as Broadcaster;
use Throwable;

/** Outbox relay: retries session events that were committed but not delivered (LT-03). */
final class RelaySessionEvents extends Command
{
    protected $signature = 'novtt:events:relay {--loop : Keep running, sweeping every two seconds}';

    protected $description = 'Deliver pending session events to the realtime server';

    public function handle(Broadcaster $broadcaster): int
    {
        do {
            try {
                $pending = SessionEvent::query()
                    ->whereNull('published_at')
                    ->where('attempts', '<', PublishSessionEvents::MAX_ATTEMPTS)
                    ->where('occurred_at', '<', now()->subSeconds(2))
                    ->orderBy('occurred_at')->orderBy('seq')
                    ->limit(200)
                    ->get();
                $sent = PublishSessionEvents::deliver($broadcaster, $pending->all());
                if ($sent > 0) {
                    $this->info("relayed {$sent} event(s)");
                }
            } catch (Throwable $e) {
                // Keep the relay alive (database not migrated yet, transient outage); retry later.
                if (!$this->option('loop')) {
                    throw $e;
                }
                $this->warn('relay sweep failed: ' . $e::class);
            }
            if ($this->option('loop')) {
                usleep(2_000_000);
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }
}
