<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\AuditTrail;
use App\Models\PresetVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Novtt\Engine\Presets\PublicationGate;

/**
 * Team-only rights review of a stored preset version (LICENSE-MATRIX, ADR 0012). Changing the
 * rights status never touches the version's content; quarantining or retiring removes it from the
 * public catalogue and from new campaigns, while campaigns already pinned keep working. There is
 * no HTTP route for this on purpose.
 */
final class SetPresetRights extends Command
{
    protected $signature = 'novtt:preset:rights {preset_version_id} {status : draft|rights_review|approved|quarantined|published|retired} {--actor= : E-mail of the team member} {--reason= : Why (kept in the audit trail)}';

    protected $description = 'Change the distribution rights status of a preset version (audited)';

    public function handle(AuditTrail $audit): int
    {
        $status = (string) $this->argument('status');
        if (!in_array($status, PublicationGate::RIGHTS_STATES, true)) {
            $this->error('Unknown status.');

            return self::FAILURE;
        }
        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if ($actor === null) {
            $this->error('Unknown --actor.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            $this->error('A --reason of 1 to 1000 characters is required.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($status, $actor, $reason, $audit): int {
            $version = PresetVersion::query()->lockForUpdate()->find((string) $this->argument('preset_version_id'));
            if ($version === null) {
                $this->error('Unknown preset version.');

                return self::FAILURE;
            }
            $previous = $version->rights_status;
            $version->update(['rights_status' => $status, 'rights_updated_at' => now()]);
            $audit->record($actor, null, 'preset.rights_changed', $version, ['from' => $previous, 'to' => $status, 'reason' => $reason]);
            $this->info("{$version->getKey()}: {$previous} -> {$status}");

            return self::SUCCESS;
        });
    }
}
