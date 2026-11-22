<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\DemoMode;
use App\Demo\DemoSeeder;
use Illuminate\Console\Command;

/** Local-only: creates the demonstration campaign (see App\Demo\DemoMode). */
final class SeedDemo extends Command
{
    protected $signature = 'novtt:demo:seed';

    protected $description = 'Create the local demonstration campaign, users and character (NOVTT_DEMO_MODE only)';

    public function handle(DemoSeeder $seeder): int
    {
        if (!DemoMode::enabled()) {
            $this->error('Demo mode is disabled: set NOVTT_DEMO_MODE=true in a local environment.');

            return self::FAILURE;
        }
        $world = $seeder->seed(dirname(base_path(), 2));
        $this->info('Demo ready.');
        $this->line('Campaign: ' . $world['campaign']->getKey());
        $this->line('Character: ' . $world['character']->getKey());

        return self::SUCCESS;
    }
}
