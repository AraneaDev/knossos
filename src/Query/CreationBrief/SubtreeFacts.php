<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

use Knossos\Scan\Conventions\NameCase;

/**
 * The facts of a directory and everything below it, merged from the stored rows of each folder.
 *
 * Only what transfers to a new folder is merged: the suffix, summed per value,
 * the one with the most files winning and the name breaking ties; the name case,
 * derived by {@see NameCase} from each row's files per style (`name_styles`), so
 * folders too small to have a name case of their own still count; and a test
 * pattern with `<dir>` (a literal test folder names where one
 * folder's tests live, not where a new folder's would). Kinds and modifiers are
 * left out: in the 2026-11-14 backtest a kind learnt from a folder's own files was
 * followed 87% of the time and one learnt across a subtree 55%, modifiers 88%
 * against 73%. The used and injected types and the line counts are left out too:
 * each row keeps only its top five types, and a median does not add up.
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
        $sums = ['suffix' => [], 'test_path' => []];
        $styles = [];
        foreach ($rows as $row) {
            $files += $row['files'];
            foreach (is_array($row['facts']['name_styles'] ?? null) ? $row['facts']['name_styles'] : [] as $style => $count) {
                if (is_int($count)) {
                    $styles[(string) $style] = ($styles[(string) $style] ?? 0) + $count;
                }
            }
            foreach (['suffix' => 'value', 'test_path' => 'pattern'] as $key => $field) {
                $fact = $row['facts'][$key] ?? null;
                if (is_array($fact) && is_string($fact[$field] ?? null) && is_int($fact['files'] ?? null) && ($key !== 'test_path' || str_contains($fact[$field], '<dir>'))) {
                    $sums[$key][$fact[$field]] = ($sums[$key][$fact[$field]] ?? 0) + $fact['files'];
                }
            }
        }

        $facts = [];
        foreach (['suffix' => 'value', 'test_path' => 'pattern'] as $key => $field) {
            $best = self::most($sums[$key]);
            if ($best !== null) {
                $facts[$key] = [$field => $best, 'files' => $sums[$key][$best]];
            }
        }
        $nameCase = NameCase::of($styles);
        if ($nameCase !== null) {
            $facts['name_case'] = $nameCase;
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
