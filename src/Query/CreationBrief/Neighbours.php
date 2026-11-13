<?php

declare(strict_types=1);

namespace Knossos\Query\CreationBrief;

/**
 * Picks the existing files a new one is best read beside.
 *
 * The order is a stable one, so the same graph names the same neighbours: a
 * file whose top-level name ends in the same CamelCase word as the new file's
 * (`Service`, `Test`) comes first, then the file whose outgoing dependencies
 * look most like the folder's usual set, then the path.
 */
final class Neighbours
{
    /** How many neighbours a brief names. */
    public const MAX = 3;

    /** How many of the folder's most common dependencies stand for its usual set. */
    private const USUAL = 5;

    /**
     * Up to MAX paths of the candidates, best first.
     *
     * The folder's usual set is the USUAL dependencies the most candidates have
     * (the name breaking ties). Similarity is the Jaccard index of a candidate's
     * dependencies against that set, compared as integer cross-products so no
     * float decides an order.
     *
     * @param string $name the new file's name without its extensions, as `FooService`
     * @param list<array{path: string, top_level: string, uses: list<string>}> $candidates
     * @return list<string>
     */
    public static function rank(string $name, array $candidates): array
    {
        $word = self::lastWord($name);
        $usual = self::usual($candidates);
        $scored = [];
        foreach ($candidates as $candidate) {
            $own = array_values(array_unique($candidate['uses']));
            $common = count(array_intersect($own, $usual));
            $scored[] = [
                'path' => $candidate['path'],
                'shares' => $word !== '' && self::lastWord($candidate['top_level']) === $word,
                'common' => $common,
                'union' => count($own) + count($usual) - $common,
            ];
        }
        usort($scored, static fn(array $a, array $b): int => $b['shares'] <=> $a['shares']
            ?: ($b['common'] * max($a['union'], 1)) <=> ($a['common'] * max($b['union'], 1))
            ?: strcmp($a['path'], $b['path']));

        return array_slice(array_column($scored, 'path'), 0, self::MAX);
    }

    /**
     * The dependencies most candidates have, most first and the name breaking ties.
     *
     * @param list<array{path: string, top_level: string, uses: list<string>}> $candidates
     * @return list<string>
     */
    private static function usual(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $candidate) {
            foreach (array_unique($candidate['uses']) as $use) {
                $counts[$use] = ($counts[$use] ?? 0) + 1;
            }
        }
        $names = array_map('strval', array_keys($counts));
        usort($names, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));

        return array_slice($names, 0, self::USUAL);
    }

    /** The last CamelCase word of a name, empty for an empty name. */
    private static function lastWord(string $name): string
    {
        preg_match_all('/[A-Z][a-z0-9]*|[a-z0-9]+/', $name, $words);

        return $words[0] === [] ? '' : $words[0][count($words[0]) - 1];
    }
}
