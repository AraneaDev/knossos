<?php

declare(strict_types=1);

namespace Knossos\TestFidelity\Synthesis;

use InvalidArgumentException;

/**
 * The framework a mock template is written for, and which source chose it
 * (Decision 5): the one asked for (`explicit`), else the one the project's
 * own doubles of the language use most (`existing_doubles`, a tie going to
 * the first by name), else a dev dependency of the nearest manifest
 * (`manifest`), else the language's default (`default`).
 *
 * A double's framework names the library it was read from, not a template
 * (preflight F12), so it counts only through {@see self::FROM_DOUBLE}:
 * `pytest` doubles (monkeypatch) and the Rust crates outside Q3 count for
 * nothing, and the next source applies.
 */
final class FrameworkChoice
{
    /** The frameworks each language's templates are written for, the default first. */
    public const FRAMEWORKS = [
        'typescript' => ['vitest', 'jest'],
        'php' => ['phpunit', 'mockery', 'prophecy'],
        'python' => ['unittest.mock', 'pytest-mock'],
        'rust' => ['mockall', 'hand-written'],
    ];

    /** A test double's framework => the template it counts for. */
    private const FROM_DOUBLE = [
        'unittest' => 'unittest.mock',
        'pytest-mock' => 'pytest-mock',
        'pest' => 'mockery',
        'mockery' => 'mockery',
        'phpunit' => 'phpunit',
        'prophecy' => 'prophecy',
        'vitest' => 'vitest',
        'jest' => 'jest',
        'mockall' => 'mockall',
        'hand-written' => 'hand-written',
    ];

    private function __construct() {}

    /**
     * The framework for a template of `$language`, and its source.
     *
     * @param array<string, int> $doubles each double framework the project's doubles of the language use, and how many do
     * @param list<string> $manifest the template frameworks the nearest manifest names, in its order of preference
     * @return array{framework: string, source: 'explicit'|'existing_doubles'|'manifest'|'default'}
     *
     * @throws InvalidArgumentException for an explicit framework no template of the language is written for
     */
    public static function choose(string $language, ?string $explicit, array $doubles, array $manifest): array
    {
        $frameworks = self::FRAMEWORKS[$language] ?? throw new InvalidArgumentException(sprintf('No mock template is written for %s.', $language));
        if ($explicit !== null) {
            if (in_array($explicit, $frameworks, true)) {
                return ['framework' => $explicit, 'source' => 'explicit'];
            }
            $owner = self::languageOf($explicit);
            throw new InvalidArgumentException($owner === null
                ? sprintf('Unknown framework "%s"; a %s template is written for %s.', $explicit, $language, self::listed($frameworks))
                : sprintf('Framework "%s" writes %s templates, and the symbol is %s: use %s.', $explicit, $owner, $language, self::listed($frameworks)));
        }
        $counts = [];
        foreach ($doubles as $framework => $count) {
            $template = self::FROM_DOUBLE[$framework] ?? null;
            if ($template !== null && in_array($template, $frameworks, true) && $count > 0) {
                $counts[$template] = ($counts[$template] ?? 0) + $count;
            }
        }
        if ($counts !== []) {
            ksort($counts, SORT_STRING);
            $most = max($counts);

            return ['framework' => (string) array_search($most, $counts, true), 'source' => 'existing_doubles'];
        }
        foreach ($manifest as $framework) {
            if (in_array($framework, $frameworks, true)) {
                return ['framework' => $framework, 'source' => 'manifest'];
            }
        }

        return ['framework' => $frameworks[0], 'source' => 'default'];
    }

    /** The language whose templates `$framework` writes; null for none. */
    public static function languageOf(string $framework): ?string
    {
        foreach (self::FRAMEWORKS as $language => $frameworks) {
            if (in_array($framework, $frameworks, true)) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Every framework a template is written for, by language then preference.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::FRAMEWORKS));
    }

    /**
     * The frameworks as a message lists them: comma-separated, the last after `or`.
     *
     * @param list<string> $frameworks
     */
    private static function listed(array $frameworks): string
    {
        $last = array_pop($frameworks);

        return $frameworks === [] ? (string) $last : implode(', ', $frameworks) . ' or ' . $last;
    }
}
