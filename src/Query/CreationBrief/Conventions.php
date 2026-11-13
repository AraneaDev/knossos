<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

/**
 * Turns the facts of one folder population into the conventions worth stating.
 *
 * A brief that says "files here are final" when five of eleven are would send a
 * new file the wrong way, so a pattern is shown only above a support threshold:
 * at least MIN_FILES files, and at least 80% of them. The share is compared as
 * integers, so exactly four in five is in and there is no float to round.
 */
final class Conventions
{
    /** Fewest files a population needs before any convention is stated. */
    public const MIN_FILES = 5;

    /** Smallest share of the population a pattern must cover, as a fraction; compared in integers. */
    public const MIN_SHARE = 0.8;

    private const KIND_TEXT = [
        'one_class' => 'one class per file',
        'one_interface' => 'one interface per file',
        'one_trait' => 'one trait per file',
        'one_enum' => 'one enum per file',
        'functions_only' => 'functions only',
    ];

    /** How many injected types, and how many used types, a brief names. */
    private const TYPES_SHOWN = 2;

    /**
     * The conventions a population of `$files` files supports, in a fixed order.
     *
     * Order is kind, modifiers (alphabetical), suffix, injects, uses, lines, so
     * the same graph always renders the same brief. Fewer than MIN_FILES files
     * yield nothing.
     *
     * @param array<string, mixed> $facts kinds, modifiers, suffix, injects, uses and lines, each optional
     * @return list<array{id: string, text: string, files: int, of: int}>
     */
    public static function shown(int $files, array $facts): array
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

    /** Whether `$count` of `$files` reaches the share, as `count * 5 >= files * 4`. */
    private static function passes(int $count, int $files): bool
    {
        return $count * 5 >= $files * 4;
    }

    /** One entry of a count map, zero when the map or the entry is absent. */
    private static function count(mixed $counts, string $key): int
    {
        return is_array($counts) && is_int($counts[$key] ?? null) ? $counts[$key] : 0;
    }

    /**
     * The most widespread types that pass, most files first and the name breaking ties.
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
            if (is_array($entry) && is_string($entry['type'] ?? null) && is_int($entry['files'] ?? null) && self::passes($entry['files'], $files)) {
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
