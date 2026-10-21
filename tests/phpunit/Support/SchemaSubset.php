<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * Checks a decoded JSON value against the subset of JSON Schema the scanner
 * schemas use: local `$ref`, `allOf`, `if`/`then`, `type`, `const`, `enum`,
 * `required`, `properties`, `additionalProperties: false`, `items`,
 * `minItems`, `uniqueItems`, `minLength`, `pattern` and `minimum`. A keyword
 * outside that subset is refused rather than ignored, so a schema change that
 * needs a real validator fails loudly.
 */
final class SchemaSubset
{
    private const KNOWN = [
        '$schema', '$id', '$ref', '$defs', 'title', 'description', 'allOf', 'if', 'then', 'type', 'const', 'enum',
        'required', 'properties', 'additionalProperties', 'items', 'minItems', 'uniqueItems', 'minLength', 'pattern', 'minimum',
    ];

    /**
     * Where `$value` breaks `$schema`, one message per violation; empty when it conforms.
     *
     * @param array<string, mixed>|bool $schema
     * @param array<string, mixed> $root the document `$ref`s resolve against
     * @return list<string>
     */
    public static function errors(array|bool $schema, mixed $value, array $root, string $at = '$'): array
    {
        if ($schema === true) {
            return [];
        }
        if ($schema === false) {
            return ["{$at}: no value is allowed"];
        }
        $unknown = array_diff(array_keys($schema), self::KNOWN);
        if ($unknown !== []) {
            throw new \LogicException('Unsupported schema keywords: ' . implode(', ', $unknown));
        }
        $errors = [];
        if (isset($schema['$ref'])) {
            $errors = [...$errors, ...self::errors(self::resolve($schema['$ref'], $root), $value, $root, $at)];
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $errors = [...$errors, ...self::errors($part, $value, $root, $at)];
        }
        if (isset($schema['if']) && self::errors($schema['if'], $value, $root, $at) === [] && isset($schema['then'])) {
            $errors = [...$errors, ...self::errors($schema['then'], $value, $root, $at)];
        }
        if (isset($schema['type']) && !self::hasType($value, $schema['type'])) {
            return [...$errors, "{$at}: expected {$schema['type']}"];
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$at}: expected " . json_encode($schema['const']);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: not one of " . json_encode($schema['enum']);
        }
        if (is_array($value) && (!array_is_list($value) || $value === [])) {
            $errors = [...$errors, ...self::objectErrors($schema, $value, $root, $at)];
        }
        if (is_array($value) && array_is_list($value)) {
            $errors = [...$errors, ...self::listErrors($schema, $value, $root, $at)];
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "{$at}: shorter than {$schema['minLength']}";
            }
            if (isset($schema['pattern']) && preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $value) !== 1) {
                $errors[] = "{$at}: does not match {$schema['pattern']}";
            }
        }
        if (is_int($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$at}: below {$schema['minimum']}";
        }

        return $errors;
    }

    /**
     * The object keywords' violations.
     *
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     * @param array<string, mixed> $root
     * @return list<string>
     */
    private static function objectErrors(array $schema, array $value, array $root, string $at): array
    {
        $errors = [];
        foreach ($schema['required'] ?? [] as $key) {
            if (!array_key_exists($key, $value)) {
                $errors[] = "{$at}: missing {$key}";
            }
        }
        foreach ($value as $key => $item) {
            if (isset($schema['properties'][$key])) {
                $errors = [...$errors, ...self::errors($schema['properties'][$key], $item, $root, "{$at}.{$key}")];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$at}: unexpected {$key}";
            }
        }

        return $errors;
    }

    /**
     * The array keywords' violations.
     *
     * @param array<string, mixed> $schema
     * @param list<mixed> $value
     * @param array<string, mixed> $root
     * @return list<string>
     */
    private static function listErrors(array $schema, array $value, array $root, string $at): array
    {
        $errors = [];
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $errors[] = "{$at}: fewer than {$schema['minItems']} items";
        }
        if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map('serialize', $value))) !== count($value)) {
            $errors[] = "{$at}: items are not unique";
        }
        foreach (isset($schema['items']) ? $value : [] as $index => $item) {
            $errors = [...$errors, ...self::errors($schema['items'], $item, $root, "{$at}[{$index}]")];
        }

        return $errors;
    }

    /** Whether a decoded value has a JSON Schema type; an empty array counts as object and array alike. */
    private static function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => throw new \LogicException("Unsupported schema type: {$type}"),
        };
    }

    /**
     * The local definition a `#/...` reference names.
     *
     * @param array<string, mixed> $root
     * @return array<string, mixed>|bool
     */
    private static function resolve(string $reference, array $root): array|bool
    {
        if (!str_starts_with($reference, '#/')) {
            throw new \LogicException("Only local references are supported: {$reference}");
        }
        $node = $root;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $node = $node[$segment];
        }

        return $node;
    }
}
