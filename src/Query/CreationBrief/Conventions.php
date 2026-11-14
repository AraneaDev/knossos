<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Scan\Conventions\SupportThreshold;

/**
 * Turns the facts of one folder population into the conventions worth stating.
 *
 * A brief that says "files here are final" when five of eleven are would send a
 * new file the wrong way, so a pattern is shown only above a support threshold:
 * at least MIN_FILES files, and at least 80% of them, 90% for a used or injected
 * type. The share is compared as integers, so exactly four in five is in and
 * there is no float to round.
 */
final class Conventions
{
    /** Fewest files a population needs before any convention is stated ({@see SupportThreshold}). */
    public const MIN_FILES = SupportThreshold::MIN_FILES;

    /** Smallest share of the population a pattern must cover, as a fraction (4/5); compared in integers. */
    public const SHARE_NUMERATOR = SupportThreshold::SHARE_NUMERATOR;

    /** The denominator of the share above. */
    public const SHARE_DENOMINATOR = SupportThreshold::SHARE_DENOMINATOR;

    private const KIND_TEXT = [
        'one_class' => 'one class per file',
        'one_interface' => 'one interface per file',
        'one_trait' => 'one trait per file',
        'one_enum' => 'one enum per file',
        'functions_only' => 'functions only',
        'types_only' => 'types only',
    ];

    /** How each naming style is stated. */
    private const NAME_CASE_TEXT = [
        'kebab' => 'kebab-case file names',
        'snake' => 'snake_case file names',
        'pascal' => 'PascalCase file names',
        'camel' => 'camelCase file names',
    ];

    /** How many injected types, and how many used types, a brief names. */
    private const TYPES_SHOWN = 2;

    /**
     * The conventions a population of `$files` files supports, in a fixed order.
     *
     * Order is kind, modifiers (alphabetical), suffix, name case, injects,
     * uses, lines, so the same graph always renders the same brief. Fewer than
     * MIN_FILES files yield nothing. The name case is stated for a source folder
     * only: a test folder's names are fixed by its test runner.
     *
     * @param array<string, mixed> $facts kinds, modifiers, suffix, name_case, injects, uses and lines, each optional
     * @param string $role the population's role, `source` or `test`
     * @return list<array{id: string, text: string, files: int, of: int}>
     */
    public static function shown(int $files, array $facts, string $role): array
    {
        if ($files < self::MIN_FILES) {
            return [];
        }
        $shown = [];
        foreach (self::KIND_TEXT as $kind => $text) {
            $count = self::count($facts['kinds'] ?? null, $kind);
            if (self::passes($count, $files)) {
                $shown[] = ['id' => 'kind', 'text' => $text, 'files' => $count, 'of' => $files];
                break;
            }
        }

        $modifiers = is_array($facts['modifiers'] ?? null) ? $facts['modifiers'] : [];
        ksort($modifiers, SORT_STRING);
        foreach ($modifiers as $name => $count) {
            if (is_int($count) && self::passes($count, $files)) {
                $shown[] = ['id' => 'modifier:' . $name, 'text' => (string) $name, 'files' => $count, 'of' => $files];
            }
        }

        $suffix = $facts['suffix'] ?? null;
        if (is_array($suffix) && is_string($suffix['value'] ?? null) && is_int($suffix['files'] ?? null) && self::passes($suffix['files'], $files)) {
            $shown[] = ['id' => 'suffix', 'text' => 'named *' . $suffix['value'], 'files' => $suffix['files'], 'of' => $files];
        }

        $nameCase = $facts['name_case'] ?? null;
        if ($role === 'source' && is_array($nameCase) && is_string($nameCase['value'] ?? null) && isset(self::NAME_CASE_TEXT[$nameCase['value']]) && is_int($nameCase['files'] ?? null) && self::passes($nameCase['files'], $files)) {
            $shown[] = ['id' => 'name_case', 'text' => self::NAME_CASE_TEXT[$nameCase['value']], 'files' => $nameCase['files'], 'of' => $files];
        }

        foreach (['injects' => 'inject', 'uses' => 'use'] as $key => $verb) {
            foreach (self::topTypes($facts[$key] ?? null, $files) as $type) {
                $shown[] = ['id' => $key . ':' . $type['type'], 'text' => $verb . ' ' . self::shortName($type['type']), 'files' => $type['files'], 'of' => $files];
            }
        }

        $lines = $facts['lines'] ?? null;
        if (is_array($lines) && is_int($lines['median'] ?? null) && is_int($lines['max'] ?? null)) {
            $shown[] = ['id' => 'lines', 'text' => sprintf('median %d lines, largest %d', $lines['median'], $lines['max']), 'files' => $files, 'of' => $files];
        }

        return $shown;
    }

    /** Whether `$count` of `$files` reaches the share, as `count * 5 >= files * 4` by default. */
    private static function passes(int $count, int $files, int $numerator = self::SHARE_NUMERATOR, int $denominator = self::SHARE_DENOMINATOR): bool
    {
        return $count * $denominator >= $files * $numerator;
    }

    /** One entry of a count map, zero when the map or the entry is absent. */
    private static function count(mixed $counts, string $key): int
    {
        return is_array($counts) && is_int($counts[$key] ?? null) ? $counts[$key] : 0;
    }

    /**
     * The most widespread types that nine in ten files have, most files first and the name breaking ties.
     *
     * @return list<array{type: string, files: int}>
     */
    private static function topTypes(mixed $types, int $files): array
    {
        if (!is_array($types)) {
            return [];
        }
        $passing = [];
        foreach ($types as $entry) {
            if (is_array($entry) && is_string($entry['type'] ?? null) && is_int($entry['files'] ?? null) && self::passes($entry['files'], $files, SupportThreshold::TYPE_SHARE_NUMERATOR, SupportThreshold::TYPE_SHARE_DENOMINATOR)) {
                $passing[] = ['type' => $entry['type'], 'files' => $entry['files']];
            }
        }
        usort($passing, static fn(array $a, array $b): int => [$b['files'], $a['type']] <=> [$a['files'], $b['type']]);

        return array_slice($passing, 0, self::TYPES_SHOWN);
    }

    /** The part of a type name after its last `\` or `::`. */
    private static function shortName(string $type): string
    {
        $afterNamespace = strrchr($type, '\\');
        $name = $afterNamespace === false ? $type : substr($afterNamespace, 1);
        $afterScope = strrpos($name, '::');

        return $afterScope === false ? $name : substr($name, $afterScope + 2);
    }
}
