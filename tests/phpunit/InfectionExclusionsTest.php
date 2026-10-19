<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use Infection\Differ\DiffSourceCodeMatcher;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Pins every `ignoreSourceCodeByRegex` exclusion in infection.json5 to the exact
 * `file:line` set it matches under src/.
 *
 * A pattern hides every mutant of its mutator on each line it matches, so a
 * new line of the same shape is hidden the moment it is written, without
 * anyone checking that its mutants are as equivalent as the comment argues.
 * docs/adr/0005-mutant-exclusions.md makes that check the price of an
 * exclusion; this test collects it. A match that appears, disappears or moves
 * fails with the mutator and the pattern, so you know which comment to re-read
 * before you update the list below.
 *
 * Lines are matched with Infection's own DiffSourceCodeMatcher, so the pin sees
 * a line as hidden exactly when Infection does.
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
                'src/Query/AbstractArchitectureQueryService.php:370',
                'src/Query/FlowQuery.php:224',
                'src/Query/FlowQuery.php:253',
                'src/Query/ImpactAnalysisQuery.php:270',
                'src/Query/InheritanceGraph.php:173',
            ],
            '.*\\(int\\) \\(\\(hrtime\\(true\\) - \\$\\w+\\) / 1_000_000\\);' => [
                'src/Git/GitProcessRunner.php:199',
            ],
            '.*bindValue\\(\':limit\', \\$\\w+ \\+ 1, PDO::PARAM_INT\\).*' => [
                'src/Query/ComponentQueryService.php:199',
                'src/Query/GraphSummaryQuery.php:77',
                'src/Query/LocationSuggestionService.php:147',
                'src/Query/ProjectCatalogQueryService.php:52',
                'src/Query/ProjectCatalogQueryService.php:139',
                'src/Query/ProjectCatalogQueryService.php:167',
            ],
            '.*LIMIT %d\', self::\\w+ \\+ 1\\).*' => [
                'src/Query/ChangeImpactQueryService.php:372',
            ],
            '.*\\$timeoutMs \\* 1_000_000.*' => [
                'src/Git/GitProcessRunner.php:552',
                'src/Query/ArchitectureHealthQuery.php:62',
                'src/Query/ArchitecturePolicyQueryService.php:93',
                'src/Query/ChangeImpactQueryService.php:157',
                'src/Query/ChangeImpactQueryService.php:219',
                'src/Query/DependencyCycleQuery.php:61',
                'src/Query/FlowQuery.php:92',
                'src/Query/ImpactAnalysisQuery.php:64',
                'src/Query/LocationSuggestionService.php:41',
            ],
            '.*implode\\(\',\', array_fill\\(0, count\\(.*' => [
                'src/Query/AnnotationNotes.php:48',
                'src/Query/AnnotationNotes.php:111',
                'src/Query/ArchitectureHealthQuery.php:125',
                'src/Query/ArchitectureHealthQuery.php:208',
                'src/Query/ArchitectureHealthQuery.php:402',
                'src/Query/ArchitecturePolicyQueryService.php:292',
                'src/Query/BoundaryMatrix.php:278',
                'src/Query/CandidateGraphFacts.php:302',
                'src/Query/ChangeImpactQueryService.php:363',
                'src/Query/ComponentQueryService.php:212',
                'src/Query/ComponentQueryService.php:313',
                'src/Query/ComponentQueryService.php:314',
                'src/Query/ComponentQueryService.php:315',
                'src/Query/DashboardService.php:235',
                'src/Query/DashboardService.php:262',
                'src/Query/DashboardService.php:384',
                'src/Query/DeadCodeAnalysis.php:544',
                'src/Query/DeadCodeCandidates.php:272',
                'src/Query/DeadCodeCandidates.php:315',
                'src/Query/DependencyCycleQuery.php:215',
                'src/Query/DiagramExportService.php:177',
                'src/Query/DiagramExportService.php:235',
                'src/Query/DiagramExportService.php:236',
                'src/Query/FileDetailService.php:166',
                'src/Query/FileDetailService.php:186',
                'src/Query/FileFanInQuery.php:112',
                'src/Query/FileFanInQuery.php:113',
                'src/Query/FileFanInQuery.php:157',
                'src/Query/FileFanInQuery.php:194',
                'src/Query/FileFanInQuery.php:223',
                'src/Query/FileFanInQuery.php:256',
                'src/Query/FileFanInQuery.php:257',
                'src/Query/FlowQuery.php:383',
                'src/Query/GraphSearchService.php:150',
                'src/Query/ImpactAnalysisQuery.php:401',
                'src/Query/LocationSuggestionService.php:188',
                'src/Query/PolicyScope.php:89',
                'src/Query/PolicyScope.php:151',
                'src/Query/ProjectFindings.php:150',
                'src/Query/TestReachSearch.php:235',
                'src/Query/TestReachSearch.php:236',
                'src/Scan/ScanLedger.php:312',
                'src/Store/ChunkedInQuery.php:52',
                'src/Store/SqliteGraphPruner.php:88',
                'src/Store/SqliteGraphPruner.php:109',
                'src/Store/SqliteGraphWriter.php:167',
                'src/Store/SqliteGraphWriter.php:188',
                'src/Store/SqliteGraphWriter.php:209',
                'src/Store/SqliteGraphWriter.php:230',
                'src/Store/SqliteGraphWriter.php:251',
            ],
            '.*array_chunk\\(\\$\\w+, \\d+\\).*' => [
                'src/Query/AnnotationNotes.php:45',
                'src/Scan/ScanLedger.php:311',
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
                'src/Git/GitProcessRunner.php:199',
            ],
            '.*\\$timeoutMs \\* 1_000_000.*' => [
                'src/Git/GitProcessRunner.php:552',
                'src/Query/ArchitectureHealthQuery.php:62',
                'src/Query/ArchitecturePolicyQueryService.php:93',
                'src/Query/ChangeImpactQueryService.php:157',
                'src/Query/ChangeImpactQueryService.php:219',
                'src/Query/DependencyCycleQuery.php:61',
                'src/Query/FlowQuery.php:92',
                'src/Query/ImpactAnalysisQuery.php:64',
                'src/Query/LocationSuggestionService.php:41',
            ],
            '.*implode\\(\',\', array_fill\\(0, count\\(.*' => [
                'src/Query/AnnotationNotes.php:48',
                'src/Query/AnnotationNotes.php:111',
                'src/Query/ArchitectureHealthQuery.php:125',
                'src/Query/ArchitectureHealthQuery.php:208',
                'src/Query/ArchitectureHealthQuery.php:402',
                'src/Query/ArchitecturePolicyQueryService.php:292',
                'src/Query/BoundaryMatrix.php:278',
                'src/Query/CandidateGraphFacts.php:302',
                'src/Query/ChangeImpactQueryService.php:363',
                'src/Query/ComponentQueryService.php:212',
                'src/Query/ComponentQueryService.php:313',
                'src/Query/ComponentQueryService.php:314',
                'src/Query/ComponentQueryService.php:315',
                'src/Query/DashboardService.php:235',
                'src/Query/DashboardService.php:262',
                'src/Query/DashboardService.php:384',
                'src/Query/DeadCodeAnalysis.php:544',
                'src/Query/DeadCodeCandidates.php:272',
                'src/Query/DeadCodeCandidates.php:315',
                'src/Query/DependencyCycleQuery.php:215',
                'src/Query/DiagramExportService.php:177',
                'src/Query/DiagramExportService.php:235',
                'src/Query/DiagramExportService.php:236',
                'src/Query/FileDetailService.php:166',
                'src/Query/FileDetailService.php:186',
                'src/Query/FileFanInQuery.php:112',
                'src/Query/FileFanInQuery.php:113',
                'src/Query/FileFanInQuery.php:157',
                'src/Query/FileFanInQuery.php:194',
                'src/Query/FileFanInQuery.php:223',
                'src/Query/FileFanInQuery.php:256',
                'src/Query/FileFanInQuery.php:257',
                'src/Query/FlowQuery.php:383',
                'src/Query/GraphSearchService.php:150',
                'src/Query/ImpactAnalysisQuery.php:401',
                'src/Query/LocationSuggestionService.php:188',
                'src/Query/PolicyScope.php:89',
                'src/Query/PolicyScope.php:151',
                'src/Query/ProjectFindings.php:150',
                'src/Query/TestReachSearch.php:235',
                'src/Query/TestReachSearch.php:236',
                'src/Scan/ScanLedger.php:312',
                'src/Store/ChunkedInQuery.php:52',
                'src/Store/SqliteGraphPruner.php:88',
                'src/Store/SqliteGraphPruner.php:109',
                'src/Store/SqliteGraphWriter.php:167',
                'src/Store/SqliteGraphWriter.php:188',
                'src/Store/SqliteGraphWriter.php:209',
                'src/Store/SqliteGraphWriter.php:230',
                'src/Store/SqliteGraphWriter.php:251',
            ],
            '.*array_chunk\\(\\$\\w+, \\d+\\).*' => [
                'src/Query/AnnotationNotes.php:45',
                'src/Scan/ScanLedger.php:311',
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
                "IncrementInteger exclusion `.*array_chunk\\(\\$\\w+, \\d+\\).*` now also hides src/Planted.php:3",
                "DecrementInteger exclusion `.*array_chunk\\(\\$\\w+, \\d+\\).*` now also hides src/Planted.php:3",
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

            self::assertContains('IncrementInteger exclusion `.*\(int\) \(\(hrtime\(true\) - \$\w+\) / 1_000_000\);` no longer matches src/Git/GitProcessRunner.php:199', $violations);
            self::assertContains('IncrementInteger exclusion `.*\(int\) \(\(hrtime\(true\) - \$\w+\) / 1_000_000\);` now also hides src/Git/GitProcessRunner.php:200', $violations);
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
                        // Infection matches `^-\s*<regex>$` against the
                        // mutant's diff, where the original line is the one
                        // prefixed with a minus.
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
     * @return array<string, list<string>> mutator to its patterns, as configured
     */
    private static function exclusions(string $root): array
    {
        $config = json5_decode((string) file_get_contents($root . '/infection.json5'), true);
        if (!is_array($config) || !is_array($config['mutators'] ?? null)) {
            throw new RuntimeException('infection.json5 has no mutators object.');
        }
        $exclusions = [];
        foreach ($config['mutators'] as $mutator => $settings) {
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
