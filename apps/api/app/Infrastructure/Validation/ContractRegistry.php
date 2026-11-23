<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use InvalidArgumentException;

/** Maps versioned contract names to the schema files under the repository's contracts/ directory. */
final readonly class ContractRegistry
{
    private const FILES = [
        'novtt.preset-manifest/1' => 'presets/preset-manifest.v1.schema.json',
        'novtt.sheet-ui/1' => 'presets/sheet-ui.v1.schema.json',
        'novtt.rules/1' => 'presets/rules.v1.schema.json',
        'novtt.preset-manifest/2' => 'presets/preset-manifest.v2.schema.json',
        'novtt.rules/2' => 'presets/rules.v2.schema.json',
        'novtt.compendium/1' => 'presets/compendium.v1.schema.json',
        'novtt.sheet-change-set/1' => 'sheets/sheet-change-set.v1.schema.json',
        'novtt.validation-result/1' => 'sheets/validation-result.v1.schema.json',
    ];

    public function __construct(private string $contractsPath)
    {
    }

    public function path(string $contract): string
    {
        $relative = self::FILES[$contract] ?? throw new InvalidArgumentException("Unknown contract {$contract}");

        return rtrim($this->contractsPath, '/') . '/' . $relative;
    }

    /** @return array<string, mixed> */
    public function schema(string $contract): array
    {
        return json_decode((string) file_get_contents($this->path($contract)), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function contracts(): array
    {
        return array_keys(self::FILES);
    }
}
