<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use Infection\Differ\DiffSourceCodeMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Pins every `ignoreSourceCodeByRegex` exclusion in infection.json5 to the exact
 * `file:line` set it matches under src/, and refuses every other way the
 * config or the source could hide a mutant.
 *
 * A pattern hides every mutant of its mutator on each line it matches, so a
 * new line of the same shape is hidden the moment it is written, without
 * anyone checking that its mutants are as equivalent as the comment argues.
 * docs/adr/0005-mutant-exclusions.md makes that check the price of an
 * exclusion; this test collects it. A match that appears, disappears or moves
 * fails with the mutator and the pattern, so you know which comment to re-read
 * before you update the list below.
 *
 * Infection matches `^-\s*<regex>$` with the `m` flag against a mutant's whole
 * unified diff, context lines included. A pattern that can match a newline can
 * therefore start on the removed line and run on into the lines after it,
 * hiding mutants the line-by-line pin below never sees. So a pattern is refused
 * outright when it holds any construct that can match a newline (`\s`, a
 * negated class, `\D`, `\W`, `\R`, an `s` flag and the like), and only then is
 * it matched line by line, with Infection's own DiffSourceCodeMatcher, against
 * a removed line alone. Under that rule the two agree: a pattern that cannot
 * leave its line matches the diff exactly when it matches the removed line.
 *
 * The other ways to hide a mutant are refused rather than pinned, because none
 * is in use: a mutator or profile switched off, `global-ignore`,
 * `global-ignoreSourceCodeByRegex`, a mutator's `ignore` list,
 * `source.excludes`, a source tree other than src/, and an
 * `@infection-ignore-all` annotation in the source.
 */
#[Group('quality')]
final class InfectionExclusionsTest extends KnossosTestCase
{
    /**
     * Mutator to pattern to the `file:line` sites it matches, in path order.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array PINS = [
        'IncrementInteger' => [
            '(?:break|continue) \\d+;' => [
                'src/Discovery/Manifest/HtmlManifest.php:164',
                'src/Query/AbstractArchitectureQueryService.php:395',
                'src/Query/FlowQuery.php:248',
                'src/Query/FlowQuery.php:277',
                'src/Query/ImpactAnalysisQuery.php:191',
                'src/Query/InheritanceGraph.php:176',
            ],
            '.*\\(int\\) \\(\\(hrtime\\(true\\) - \\$\\w+\\) / 1_000_000\\);' => [
                'src/Git/GitProcessRunner.php:205',
            ],
            '.*bindValue\\(\':limit\', \\$\\w+ \\+ 1, PDO::PARAM_INT\\).*' => [
                'src/Query/ComponentQueryService.php:200',
                'src/Query/GraphSummaryQuery.php:81',
                'src/Query/LocationSuggestionService.php:142',
                'src/Query/ProjectCatalogQueryService.php:139',
                'src/Store/ProjectRepository.php:206',
                'src/Store/ProjectRepository.php:230',
            ],
            '.*LIMIT %d\', self::\\w+ \\+ 1\\).*' => [
                'src/Query/ChangeImpactQueryService.php:376',
            ],
            '.*implode\\(\',\', array_fill\\(0, count\\(.*' => [
                'src/Query/AnnotationNotes.php:83',
                'src/Query/AnnotationNotes.php:146',
                'src/Query/ArchitectureHealthQuery.php:128',
                'src/Query/ArchitectureHealthQuery.php:211',
                'src/Query/ArchitecturePolicyQueryService.php:352',
                'src/Query/BoundaryMatrix.php:275',
                'src/Query/CandidateGraphFacts.php:302',
                'src/Query/ChangeImpactQueryService.php:367',
                'src/Query/ComponentQueryService.php:213',
                'src/Query/ComponentQueryService.php:314',
                'src/Query/ComponentQueryService.php:315',
                'src/Query/ComponentQueryService.php:316',
                'src/Query/DashboardService.php:237',
                'src/Query/DashboardService.php:264',
                'src/Query/DashboardService.php:386',
                'src/Query/DeadCodeAnalysis.php:565',
                'src/Query/DeadCodeCandidates.php:274',
                'src/Query/DependencyCycleQuery.php:219',
                'src/Query/DiagramExportService.php:177',
                'src/Query/DiagramExportService.php:235',
                'src/Query/DiagramExportService.php:236',
                'src/Query/FileDetailService.php:166',
                'src/Query/FileDetailService.php:186',
                'src/Query/FileFanInQuery.php:114',
                'src/Query/FileFanInQuery.php:115',
                'src/Query/FileFanInQuery.php:159',
                'src/Query/FileFanInQuery.php:196',
                'src/Query/FileFanInQuery.php:225',
                'src/Query/FileFanInQuery.php:258',
                'src/Query/FileFanInQuery.php:259',
                'src/Query/GraphSearchService.php:154',
                'src/Query/PolicyScope.php:89',
                'src/Query/PolicyScope.php:151',
                'src/Query/ProjectFindings.php:153',
                'src/Query/TestReachSearch.php:227',
                'src/Query/TestReachSearch.php:228',
                'src/Scan/ScanLedger.php:309',
                'src/Store/ChunkedInQuery.php:52',
                'src/Store/EdgeQueries.php:21',
                'src/Store/SqliteGraphPruner.php:88',
                'src/Store/SqliteGraphPruner.php:109',
                'src/Store/SqliteGraphWriter.php:167',
                'src/Store/SqliteGraphWriter.php:188',
                'src/Store/SqliteGraphWriter.php:209',
                'src/Store/SqliteGraphWriter.php:230',
                'src/Store/SqliteGraphWriter.php:251',
            ],
            '.*array_chunk\\(\\$\\w+, \\d+\\).*' => [
                'src/Query/AnnotationNotes.php:80',
                'src/Scan/ScanLedger.php:308',
                'src/Store/SqliteGraphPruner.php:87',
                'src/Store/SqliteGraphPruner.php:108',
                'src/Store/SqliteGraphWriter.php:166',
                'src/Store/SqliteGraphWriter.php:187',
                'src/Store/SqliteGraphWriter.php:208',
                'src/Store/SqliteGraphWriter.php:229',
                'src/Store/SqliteGraphWriter.php:250',
            ],
        ],
        'DecrementInteger' => [
            '.*\\(int\\) \\(\\(hrtime\\(true\\) - \\$\\w+\\) / 1_000_000\\);' => [
                'src/Git/GitProcessRunner.php:205',
            ],
            '.*implode\\(\',\', array_fill\\(0, count\\(.*' => [
                'src/Query/AnnotationNotes.php:83',
                'src/Query/AnnotationNotes.php:146',
                'src/Query/ArchitectureHealthQuery.php:128',
                'src/Query/ArchitectureHealthQuery.php:211',
                'src/Query/ArchitecturePolicyQueryService.php:352',
                'src/Query/BoundaryMatrix.php:275',
                'src/Query/CandidateGraphFacts.php:302',
                'src/Query/ChangeImpactQueryService.php:367',
                'src/Query/ComponentQueryService.php:213',
                'src/Query/ComponentQueryService.php:314',
                'src/Query/ComponentQueryService.php:315',
                'src/Query/ComponentQueryService.php:316',
                'src/Query/DashboardService.php:237',
                'src/Query/DashboardService.php:264',
                'src/Query/DashboardService.php:386',
                'src/Query/DeadCodeAnalysis.php:565',
                'src/Query/DeadCodeCandidates.php:274',
                'src/Query/DependencyCycleQuery.php:219',
                'src/Query/DiagramExportService.php:177',
                'src/Query/DiagramExportService.php:235',
                'src/Query/DiagramExportService.php:236',
                'src/Query/FileDetailService.php:166',
                'src/Query/FileDetailService.php:186',
                'src/Query/FileFanInQuery.php:114',
                'src/Query/FileFanInQuery.php:115',
                'src/Query/FileFanInQuery.php:159',
                'src/Query/FileFanInQuery.php:196',
                'src/Query/FileFanInQuery.php:225',
                'src/Query/FileFanInQuery.php:258',
                'src/Query/FileFanInQuery.php:259',
                'src/Query/GraphSearchService.php:154',
                'src/Query/PolicyScope.php:89',
                'src/Query/PolicyScope.php:151',
                'src/Query/ProjectFindings.php:153',
                'src/Query/TestReachSearch.php:227',
                'src/Query/TestReachSearch.php:228',
                'src/Scan/ScanLedger.php:309',
                'src/Store/ChunkedInQuery.php:52',
                'src/Store/EdgeQueries.php:21',
                'src/Store/SqliteGraphPruner.php:88',
                'src/Store/SqliteGraphPruner.php:109',
                'src/Store/SqliteGraphWriter.php:167',
                'src/Store/SqliteGraphWriter.php:188',
                'src/Store/SqliteGraphWriter.php:209',
                'src/Store/SqliteGraphWriter.php:230',
                'src/Store/SqliteGraphWriter.php:251',
            ],
            '.*array_chunk\\(\\$\\w+, \\d+\\).*' => [
                'src/Query/AnnotationNotes.php:80',
                'src/Scan/ScanLedger.php:308',
                'src/Store/SqliteGraphPruner.php:87',
                'src/Store/SqliteGraphPruner.php:108',
                'src/Store/SqliteGraphWriter.php:166',
                'src/Store/SqliteGraphWriter.php:187',
                'src/Store/SqliteGraphWriter.php:208',
                'src/Store/SqliteGraphWriter.php:229',
                'src/Store/SqliteGraphWriter.php:250',
            ],
        ],
        'FunctionCallRemoval' => [
            '.*stream_set_(?:read|write|chunk)_buffer\\(.*' => [
                'src/Mcp/StdioServer.php:53',
            ],
        ],
    ];

    /**
     * Escapes that can match a newline: whitespace, the negations of digit,
     * word and horizontal space, any newline sequence, vertical space, a whole
     * grapheme, a single byte, and any way of spelling a character by number,
     * control letter or Unicode property.
     */
    private const NEWLINE_ESCAPES = ['s', 'D', 'W', 'H', 'R', 'v', 'X', 'C', 'n', 'r', 'f', 'x', 'o', 'c', 'p', 'P', '0', 'N{'];

    public function testEveryExclusionIsPinned(): void
    {
        $patterns = [];
        foreach (self::exclusions(self::repositoryRoot()) as $mutator => $regexes) {
            foreach ($regexes as $regex) {
                $patterns[] = $mutator . ' ' . $regex;
            }
        }
        $pinned = [];
        foreach (self::PINS as $mutator => $regexes) {
            foreach (array_keys($regexes) as $regex) {
                $pinned[] = $mutator . ' ' . $regex;
            }
        }

        self::assertSame($pinned, $patterns, 'Every ignoreSourceCodeByRegex entry in infection.json5 needs its sites pinned here, in the same order.');
        self::assertSame([], self::refusals(self::repositoryRoot()));
    }

    public function testEachExclusionMatchesExactlyItsPinnedSites(): void
    {
        self::assertSame([], self::violations(self::repositoryRoot()));
    }

    public function testANewMatchingLineFailsThePinAndNamesTheExclusion(): void
    {
        $root = self::scratchCopy();
        try {
            file_put_contents($root . '/src/Planted.php', "<?php\n\nforeach (array_chunk(\$rows, 50) as \$chunk) {\n}\n");

            self::assertSame([
                'IncrementInteger exclusion `.*array_chunk\\(\$\\w+, \\d+\\).*` now also hides src/Planted.php:3',
                'DecrementInteger exclusion `.*array_chunk\\(\$\\w+, \\d+\\).*` now also hides src/Planted.php:3',
            ], self::violations($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    public function testAMovedLineFailsThePinAtBothItsOldAndItsNewSite(): void
    {
        $root = self::scratchCopy();
        try {
            $file = $root . '/src/Git/GitProcessRunner.php';
            file_put_contents($file, preg_replace('/^<\?php\n/', "<?php\n\n", (string) file_get_contents($file)));

            $violations = self::violations($root);

            self::assertContains('IncrementInteger exclusion `.*\(int\) \(\(hrtime\(true\) - \$\w+\) / 1_000_000\);` no longer matches src/Git/GitProcessRunner.php:205', $violations);
            self::assertContains('IncrementInteger exclusion `.*\(int\) \(\(hrtime\(true\) - \$\w+\) / 1_000_000\);` now also hides src/Git/GitProcessRunner.php:206', $violations);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function patternsThatCanCrossANewline(): iterable
    {
        yield 'whitespace' => ['.*int \$\w+ =\s*\d+.*'];
        yield 'a negated class' => ['.*int \$\w+ = [^;]*'];
        yield 'not a digit' => ['.*int \$\w+ =\D+\d+.*'];
        yield 'not a word character' => ['.*int\W+\$\w+.*'];
        yield 'any newline sequence' => ['.*\R.*'];
        yield 'vertical space' => ['.*int\v.*'];
        yield 'a character by number' => ['.*\x0a.*'];
        yield 'a dot-all flag' => ['(?s).*int \$\w+ = \d+.*'];
        yield 'a scoped dot-all flag' => ['(?is:.*int \$\w+ = \d+.*)'];
        yield 'a newline convention verb' => ['(*CR).*int.*'];
        yield 'a POSIX class' => ['.*int[[:space:]]+\$\w+.*'];
        yield 'a class range from a numbered character' => ['.*int[\t-z]+.*'];
    }

    #[DataProvider('patternsThatCanCrossANewline')]
    public function testAPatternThatCanCrossANewlineIsRefusedByName(string $pattern): void
    {
        $refusals = self::refusalsWith(static function (array $config) use ($pattern): array {
            $config['mutators']['IncrementInteger']['ignoreSourceCodeByRegex'][] = $pattern;

            return $config;
        });

        self::assertCount(1, $refusals);
        self::assertStringStartsWith(sprintf('IncrementInteger exclusion `%s` can match a newline (', $pattern), $refusals[0]);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function otherWaysToHideAMutant(): iterable
    {
        yield 'a global source pattern' => [static function (array $c): array {
            $c['mutators']['global-ignoreSourceCodeByRegex'] = ['.*foo.*'];
            return $c;
        }, 'mutators.global-ignoreSourceCodeByRegex is set'];
        yield 'a global ignore list' => [static function (array $c): array {
            $c['mutators']['global-ignore'] = ['Knossos\\Mcp\\StdioServer'];
            return $c;
        }, 'mutators.global-ignore is set'];
        yield "a mutator's ignore list" => [static function (array $c): array {
            $c['mutators']['IncrementInteger']['ignore'] = ['Knossos\\Mcp\\StdioServer::run'];
            return $c;
        }, 'mutators.IncrementInteger.ignore is set'];
        yield 'an excluded source directory' => [static function (array $c): array {
            $c['source']['excludes'] = ['Mcp'];
            return $c;
        }, 'source.excludes is set'];
        yield 'a source tree other than src' => [static function (array $c): array {
            $c['source']['directories'] = ['src/Query'];
            return $c;
        }, 'source.directories must be exactly ["src"]'];
        yield 'a mutator switched off' => [static function (array $c): array {
            $c['mutators']['Plus'] = false;
            return $c;
        }, 'mutators.Plus is switched off'];
        yield 'the default profile switched off' => [static function (array $c): array {
            $c['mutators']['@default'] = false;
            return $c;
        }, 'mutators.@default is switched off'];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $edit */
    #[DataProvider('otherWaysToHideAMutant')]
    public function testEveryOtherWayToHideAMutantIsRefusedByKey(callable $edit, string $refusal): void
    {
        self::assertSame([$refusal], self::refusalsWith($edit));
    }

    public function testAnIgnoreAnnotationInTheSourceIsRefusedWhereItIs(): void
    {
        $root = self::scratchCopy();
        try {
            file_put_contents($root . '/src/Planted.php', "<?php\n\n/** @infection-ignore-all */\nfunction planted(): int\n{\n    return 1;\n}\n");

            self::assertSame(['src/Planted.php:3 has an @infection-ignore-all annotation'], self::refusals($root));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Each difference between what the patterns in `$root/infection.json5`
     * match under `$root/src` and what PINS records, one sentence per site.
     *
     * @return list<string>
     */
    private static function violations(string $root): array
    {
        $files = self::sourceFiles($root);
        $matcher = new DiffSourceCodeMatcher();
        $violations = [];
        foreach (self::exclusions($root) as $mutator => $regexes) {
            foreach ($regexes as $regex) {
                $found = [];
                foreach ($files as $relative => $lines) {
                    foreach ($lines as $index => $line) {
                        // The removed line alone, prefixed with a minus as in
                        // the diff. That is all of the diff a pattern can see
                        // once refusals() has ruled out crossing a newline.
                        if ($matcher->matches('-' . $line, $regex)) {
                            $found[] = $relative . ':' . ($index + 1);
                        }
                    }
                }
                $pinned = self::PINS[$mutator][$regex] ?? [];
                $name = sprintf('%s exclusion `%s`', $mutator, $regex);
                foreach (array_diff($found, $pinned) as $site) {
                    $violations[] = $name . ' now also hides ' . $site;
                }
                foreach (array_diff($pinned, $found) as $site) {
                    $violations[] = $name . ' no longer matches ' . $site;
                }
            }
        }

        return $violations;
    }

    /**
     * Every way `$root/infection.json5` or `$root/src` hides a mutant other
     * than a pinned pattern, and every pattern that can cross a newline.
     *
     * @return list<string>
     */
    private static function refusals(string $root): array
    {
        $config = self::config($root);
        $refusals = [];
        foreach (self::exclusions($root) as $mutator => $regexes) {
            foreach ($regexes as $regex) {
                $constructs = self::newlineConstructs($regex);
                if ($constructs !== []) {
                    $refusals[] = sprintf('%s exclusion `%s` can match a newline (%s), so it hides more of the diff than its pinned lines', $mutator, $regex, implode(', ', $constructs));
                }
            }
        }
        foreach (['global-ignore', 'global-ignoreSourceCodeByRegex'] as $key) {
            if (array_key_exists($key, $config['mutators'])) {
                $refusals[] = "mutators.{$key} is set";
            }
        }
        foreach ($config['mutators'] as $mutator => $settings) {
            if ($settings === false) {
                $refusals[] = "mutators.{$mutator} is switched off";
            } elseif (is_array($settings) && array_key_exists('ignore', $settings)) {
                $refusals[] = "mutators.{$mutator}.ignore is set";
            }
        }
        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        if (array_key_exists('excludes', $source)) {
            $refusals[] = 'source.excludes is set';
        }
        if (($source['directories'] ?? null) !== ['src']) {
            $refusals[] = 'source.directories must be exactly ["src"]';
        }
        foreach (self::sourceFiles($root) as $relative => $lines) {
            foreach ($lines as $index => $line) {
                if (str_contains($line, '@infection-ignore-all')) {
                    $refusals[] = sprintf('%s:%d has an @infection-ignore-all annotation', $relative, $index + 1);
                }
            }
        }

        return $refusals;
    }

    /**
     * The constructs in a pattern that can match a newline, as written.
     *
     * Read conservatively: a construct that could match one in some context is
     * named even if it cannot in this one, because the cost of a false refusal
     * is rewriting a pattern and the cost of a miss is a hidden mutant.
     *
     * @return list<string>
     */
    private static function newlineConstructs(string $regex): array
    {
        $found = [];
        $length = strlen($regex);
        $inClass = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $regex[$i];
            if ($char === "\n" || $char === "\r") {
                $found[] = 'a literal line break';
            } elseif ($char === '\\' && $i + 1 < $length) {
                $next = $regex[$i + 1];
                $escape = $next === 'N' && ($regex[$i + 2] ?? '') === '{' ? 'N{' : $next;
                if (in_array($escape, self::NEWLINE_ESCAPES, true)) {
                    $found[] = '\\' . $escape;
                } elseif ($inClass && ($regex[$i + 2] ?? '') === '-' && ($regex[$i + 3] ?? ']') !== ']') {
                    $found[] = 'a class range from \\' . $next;
                }
                $i++;
            } elseif ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                } elseif ($char === '[' && ($regex[$i + 1] ?? '') === ':') {
                    $found[] = 'a POSIX class';
                }
            } elseif ($char === '[') {
                $inClass = true;
                if (($regex[$i + 1] ?? '') === '^') {
                    $found[] = 'a negated class';
                    $i++;
                }
                // A `]` straight after the opener is a literal, not the close.
                if (($regex[$i + 1] ?? '') === ']') {
                    $i++;
                }
            } elseif ($char === '(' && ($regex[$i + 1] ?? '') === '*') {
                $found[] = 'a (* verb';
            } elseif ($char === '(' && ($regex[$i + 1] ?? '') === '?'
                && preg_match('/\G\(\?[a-zA-Z^-]*s[a-zA-Z-]*[:)]/', $regex, $flags, 0, $i) === 1) {
                $found[] = 'an s flag';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The refusals for a scratch copy whose infection.json5 is $edit applied
     * to the repository's own, written back as plain JSON (valid JSON5).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     * @return list<string>
     */
    private static function refusalsWith(callable $edit): array
    {
        $root = self::scratchCopy();
        try {
            file_put_contents($root . '/infection.json5', json_encode($edit(self::config($root)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::refusals($root);
        } finally {
            (new class {
                use Support\TempTrees;
            })->removeTempTree($root);
        }
    }

    /** @return array{mutators: array<string, mixed>, source?: mixed} */
    private static function config(string $root): array
    {
        $config = json5_decode((string) file_get_contents($root . '/infection.json5'), true);
        if (!is_array($config) || !is_array($config['mutators'] ?? null)) {
            throw new RuntimeException('infection.json5 has no mutators object.');
        }

        return $config;
    }

    /**
     * @return array<string, list<string>> mutator to its patterns, as configured
     */
    private static function exclusions(string $root): array
    {
        $exclusions = [];
        foreach (self::config($root)['mutators'] as $mutator => $settings) {
            if (is_array($settings) && is_array($settings['ignoreSourceCodeByRegex'] ?? null)) {
                $exclusions[(string) $mutator] = array_values(array_map('strval', $settings['ignoreSourceCodeByRegex']));
            }
        }

        return $exclusions;
    }

    /**
     * @return array<string, list<string>> path relative to $root, in order, to its lines
     */
    private static function sourceFiles(string $root): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof \SplFileInfo && $item->getExtension() === 'php') {
                $relative = substr($item->getPathname(), strlen($root) + 1);
                $files[$relative] = explode("\n", rtrim((string) file_get_contents($item->getPathname()), "\n"));
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * A temporary tree holding a copy of src/ and infection.json5, under the
     * prefix removeTempTree() agrees to delete.
     */
    private static function scratchCopy(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-infection-pins-' . bin2hex(random_bytes(6));
        (new class {
            use Support\TempTrees;
        })->copyTree(self::repositoryRoot() . '/src', $root . '/src');
        copy(self::repositoryRoot() . '/infection.json5', $root . '/infection.json5');

        return $root;
    }
}
