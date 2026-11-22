<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\PresetImporter;
use App\Http\Problems\ApiProblem;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Team-only path to the canonical catalogue (SPEC-PRESET-AUTHORING-001: canonical publication
 * only by the team after review). There is no HTTP route for this on purpose.
 */
final class PublishCanonicalPreset extends Command
{
    protected $signature = 'novtt:preset:publish {directory : Folder with manifest.json, sheet.schema.json, sheet.ui.json, rules.json} {--actor= : E-mail of the team member publishing}';

    protected $description = 'Validate and publish a reviewed preset version to the canonical catalogue';

    public function handle(PresetImporter $importer): int
    {
        $actor = User::query()->where('email', (string) $this->option('actor'))->first();
        if ($actor === null) {
            $this->error('Unknown --actor.');

            return self::FAILURE;
        }
        $dir = rtrim((string) $this->argument('directory'), '/');
        $parts = [];
        foreach (['manifest' => 'manifest.json', 'sheet_schema' => 'sheet.schema.json', 'sheet_ui' => 'sheet.ui.json', 'rules' => 'rules.json'] as $key => $file) {
            if (!is_file("{$dir}/{$file}")) {
                $this->error("Missing {$file}.");

                return self::FAILURE;
            }
            $parts[] = json_encode($key) . ':' . file_get_contents("{$dir}/{$file}");
        }
        try {
            [$package, $objects] = $importer->parse('{' . implode(',', $parts) . '}');
            [$version, $created] = $importer->import($actor, $package, $objects, canonical: true);
        } catch (ApiProblem $problem) {
            $this->error($problem->problemCode);
            $this->line(json_encode($problem->extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');

            return self::FAILURE;
        }
        $this->info(($created ? 'Published ' : 'Already published ') . $version->getKey());

        return self::SUCCESS;
    }
}
