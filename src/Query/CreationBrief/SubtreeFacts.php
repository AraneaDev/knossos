<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

/**
 * The facts of a directory and everything below it, merged from the stored rows of each folder.
 *
 * Counts add up: kinds and modifiers per key, the suffix and the name case per
 * value, the one with the most files winning and the name breaking ties. Only a
 * test pattern with `<dir>` merges, since a literal test folder names where one
 * folder's tests live and not where a new folder's would. The used and injected
 * types and the line counts are left out: each row keeps only its top five
 * types, so a sum would understate them, and a median does not add up.
 */
final class SubtreeFacts
{
    /**
     * The merged population of the rows: their files summed and their facts merged.
     *
     * @param list<array{directory: string, files: int, facts: array<string, mixed>}> $rows
     * @return array{files: int, facts: array<string, mixed>}
     */
    public static function merge(array $rows): array
    {
        $files = 0;
        $sums = ['kinds' => [], 'modifiers' => [], 'suffix' => [], 'name_case' => [], 'test_path' => []];
        foreach ($rows as $row) {
            $files += $row['files'];
            foreach (['kinds', 'modifiers'] as $key) {
                foreach (is_array($row['facts'][$key] ?? null) ? $row['facts'][$key] : [] as $name => $count) {
                    if (is_int($count)) {
                        $sums[$key][(string) $name] = ($sums[$key][(string) $name] ?? 0) + $count;
                    }
                }
            }
            foreach (['suffix' => 'value', 'name_case' => 'value', 'test_path' => 'pattern'] as $key => $field) {
                $fact = $row['facts'][$key] ?? null;
                if (is_array($fact) && is_string($fact[$field] ?? null) && is_int($fact['files'] ?? null) && ($key !== 'test_path' || str_contains($fact[$field], '<dir>'))) {
                    $sums[$key][$fact[$field]] = ($sums[$key][$fact[$field]] ?? 0) + $fact['files'];
                }
            }
        }

        $facts = [];
        foreach (['kinds', 'modifiers'] as $key) {
            if ($sums[$key] !== []) {
                ksort($sums[$key], SORT_STRING);
                $facts[$key] = $sums[$key];
            }
        }
        foreach (['suffix' => 'value', 'name_case' => 'value', 'test_path' => 'pattern'] as $key => $field) {
            $best = self::most($sums[$key]);
            if ($best !== null) {
                $facts[$key] = [$field => $best, 'files' => $sums[$key][$best]];
            }
        }

        return ['files' => $files, 'facts' => $facts];
    }

    /**
     * The value with the most files, the smaller string breaking ties, or null for none.
     *
     * @param array<string, int> $counts
     */
    private static function most(array $counts): ?string
    {
        $best = null;
        foreach ($counts as $value => $files) {
            $value = (string) $value;
            if ($best === null || $files > $counts[$best] || ($files === $counts[$best] && strcmp($value, $best) < 0)) {
                $best = $value;
            }
        }

        return $best;
    }
}
