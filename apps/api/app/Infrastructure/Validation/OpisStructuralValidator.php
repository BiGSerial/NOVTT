<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\StructuralValidator;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * JSON Schema Draft 2020-12 adapter (ADR 0010). Remote $ref resolution is disabled: only
 * schemas registered from the local contracts directory can be referenced.
 */
final class OpisStructuralValidator implements StructuralValidator
{
    public const MAX_ISSUES = 50;

    private ?Validator $validator = null;

    public function __construct(private readonly ContractRegistry $contracts)
    {
    }

    public function validate(mixed $document, array $schema): ValidationResult
    {
        $schema['$schema'] ??= 'https://json-schema.org/draft/2020-12/schema';
        unset($schema['$id']);
        $schemaObject = JsonDocument::toJsonValue($schema, ["type" => "object", "additionalProperties" => ["type" => ["object", "boolean"]]]);

        return $this->run(JsonDocument::toJsonValue($document, $schema), $schemaObject);
    }

    public function validateContract(mixed $document, string $contract): ValidationResult
    {
        $schema = $this->contracts->schema($contract);

        return $this->run(JsonDocument::toJsonValue($document, $schema), 'urn:novtt:contract:' . self::contractKey($contract));
    }

    private function run(mixed $data, mixed $schema): ValidationResult
    {
        $result = $this->validator()->validate($data, $schema);
        if ($result->isValid()) {
            return ValidationResult::ok();
        }
        $issues = [];
        $this->collect($result->error(), $issues);

        return new ValidationResult(array_slice($issues, 0, self::MAX_ISSUES));
    }

    /** @param list<ValidationIssue> $issues */
    private function collect(?ValidationError $error, array &$issues): void
    {
        if ($error === null || count($issues) >= self::MAX_ISSUES) {
            return;
        }
        $sub = $error->subErrors();
        // Wrapper errors (properties, items, $ref, allOf, ...) carry the real failures as children.
        // oneOf/anyOf are reported as one issue: listing every failed branch is noise.
        if ($sub !== [] && !in_array($error->keyword(), ['oneOf', 'anyOf'], true)) {
            foreach ($sub as $child) {
                $this->collect($child, $issues);
            }

            return;
        }
        $params = [];
        foreach ($error->args() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $params[(string) $key] = $value;
            }
        }
        $issues[] = new ValidationIssue(
            'schema.' . $error->keyword(),
            Severity::Error,
            [],
            [self::pointer($error->data()->fullPath())],
            $params,
        );
    }

    /** @param list<int|string> $path */
    private static function pointer(array $path): string
    {
        if ($path === []) {
            return '';
        }

        return '/' . implode('/', array_map(
            static fn (int|string $s): string => str_replace(['~', '/'], ['~0', '~1'], (string) $s),
            $path,
        ));
    }

    private static function contractKey(string $contract): string
    {
        // "novtt.sheet-ui/1" -> "sheet-ui:1"
        return str_replace('/', ':', substr($contract, strlen('novtt.')));
    }

    private function validator(): Validator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }
        $validator = new Validator(max_errors: self::MAX_ISSUES, stop_at_first_error: false);
        $resolver = $validator->resolver();
        foreach ($this->contracts->contracts() as $contract) {
            $resolver->registerFile('urn:novtt:contract:' . self::contractKey($contract), $this->contracts->path($contract));
        }
        $validator->parser()->setOption('defaultDraft', '2020-12');

        return $this->validator = $validator;
    }
}
