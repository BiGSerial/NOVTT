<?php

declare(strict_types=1);

namespace Novtt\Engine\Tests\Support;

use Novtt\Engine\Presets\SchemaNavigator;
use Novtt\Engine\Presets\SheetLayout;
use Novtt\Engine\Rules\Mechanics;
use Novtt\Engine\Sheets\Actor;
use Novtt\Engine\Sheets\ChangeOperation;
use Novtt\Engine\Sheets\ChangeOutcome;
use Novtt\Engine\Sheets\Phase;
use Novtt\Engine\Sheets\SheetChangeEngine;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/** Loads one of the authorial demo presets under presets/novtt-demo and drives the engine with it. */
final class DemoPreset
{
    public const ROOT = __DIR__ . '/../../../../presets/novtt-demo';
    public const PRESETS = __DIR__ . '/../../../../presets';

    public static function package(string $relative): self
    {
        return new self(basename(dirname($relative)), null, self::PRESETS . '/' . $relative);
    }

    /** @var array<string, array<string, mixed>> */
    public array $parts = [];

    /** @param array<string, mixed>|null $rulesOverride */
    /** @param string|null $directory a package directory (default: presets/novtt-demo/<variant>/1.0.0) */
    public function __construct(public readonly string $variant, ?array $rulesOverride = null, ?string $directory = null)
    {
        $directory ??= self::ROOT . "/{$variant}/1.0.0";
        foreach (['manifest', 'sheet_schema' => 'sheet.schema', 'sheet_ui' => 'sheet.ui', 'rules', 'compendium'] as $key => $file) {
            $key = is_int($key) ? $file : $key;
            $this->parts[$key] = self::json("{$directory}/{$file}.json");
        }
        if ($rulesOverride !== null) {
            $this->parts['rules'] = $rulesOverride;
        }
    }

    /** @return array<string, mixed> */
    public static function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    public function engine(): SheetChangeEngine
    {
        $port = new class () implements StructuralValidator {
            public function validate(mixed $document, array|object $schema): ValidationResult
            {
                return ValidationResult::ok();
            }

            public function validateContract(mixed $document, string $contract): ValidationResult
            {
                return ValidationResult::ok();
            }
        };

        return new SheetChangeEngine(SheetLayout::fromArray($this->parts['sheet_ui']), $this->parts['sheet_schema'], $this->parts['sheet_schema'], $this->parts['rules'], $port);
    }

    public function mechanics(): Mechanics
    {
        return new Mechanics($this->parts['rules'], new SchemaNavigator($this->parts['sheet_schema']));
    }

    /**
     * @param array<string, mixed> $sheet
     * @param list<array<string, mixed>> $ops
     * @param array<string, int> $grants
     */
    public function evaluate(array $sheet, array $ops, Phase $phase = Phase::Creation, array $grants = [], Actor $actor = Actor::Player): ChangeOutcome
    {
        return $this->engine()->evaluate($sheet, array_map(ChangeOperation::fromArray(...), $ops), $actor, $phase, $grants);
    }

    /** @return list<string> */
    public static function ruleIds(ChangeOutcome|ValidationResult $result): array
    {
        $issues = $result instanceof ChangeOutcome ? $result->result->issues : $result->issues;

        return array_map(static fn (ValidationIssue $i): string => $i->ruleId, $issues);
    }

    /** @return array<string, mixed> */
    public static function set(string $id, string $path, mixed $value): array
    {
        return ['id' => $id, 'type' => 'set_field', 'path' => $path, 'value' => $value];
    }
}
