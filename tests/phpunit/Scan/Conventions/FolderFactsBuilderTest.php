<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan\Conventions;

use Knossos\Scan\Conventions\FolderFactsBuilder;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The per-folder facts derived from a committed graph, built by hand so each
 * fact has one cause and one assertion.
 */
#[Group('scan')]
final class FolderFactsBuilderTest extends KnossosTestCase
{
    private PDO $pdo;

    /** @var array<string, string> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->pdo, , $this->ids] = $this->storeFixture();
        // The fixture's own file and classes would count as a folder; these tests describe their own.
        $this->pdo->prepare('DELETE FROM files WHERE project_id = ?')->execute([$this->ids['project']]);
        $this->pdo->prepare('DELETE FROM nodes WHERE project_id = ?')->execute([$this->ids['project']]);
    }

    /** Files are grouped by directory, extension and role, and a subfolder's files are not its parent's. */
    public function testFilesAreCountedPerDirectoryExtensionAndRole(): void
    {
        $this->file('src/A.php', 10);
        $this->file('src/B.php', 10);
        $this->file('src/a.ts', 10);
        $this->file('src/a.spec.ts', 10, [['kind' => 'module', 'name' => 'a.spec.ts', 'role' => 'quality.test_module']]);
        $this->file('src/sub/C.php', 10);

        $counts = [];
        foreach ($this->rows() as $row) {
            $counts[] = [$row['directory'], $row['extension'], $row['role'], $row['files']];
        }

        self::assertSame([
            ['src', 'php', 'source', 2],
            ['src', 'ts', 'source', 1],
            ['src', 'ts', 'test', 1],
            ['src/sub', 'php', 'source', 1],
        ], $counts);
    }

    /** A file with no dot has the empty extension, and the extension is lower case. */
    public function testTheExtensionIsTheLowerCasePartAfterTheLastDot(): void
    {
        $this->file('Makefile', 3);
        $this->file('docs/README.MD', 3);

        $keys = array_map(static fn(array $row): string => $row['directory'] . '|' . $row['extension'], $this->rows());

        self::assertSame(['|', 'docs|md'], $keys);
    }

    /** What the scanner stored on the single top-level type is what gets counted. */
    public function testModifiersAreCountedFromWhatTheScannerStored(): void
    {
        $this->file('src/A.php', 10, [['kind' => 'class', 'name' => 'A', 'attributes' => ['final' => true, 'readonly' => true, 'abstract' => false]]]);
        $this->file('src/B.php', 10, [['kind' => 'class', 'name' => 'B', 'attributes' => ['final' => true, 'readonly' => true]]]);
        $this->file('src/C.php', 10, [['kind' => 'class', 'name' => 'C', 'attributes' => ['final' => true, 'readonly' => false]]]);

        self::assertSame(['final' => 3, 'readonly' => 2], $this->facts('src')['modifiers']);
        self::assertSame(['one_class' => 3], $this->facts('src')['kinds']);
    }

    /** Files are shaped by their top-level declarations; a method or a nested function is not one. */
    public function testDeclarationShapesAreCountedFromTopLevelNodes(): void
    {
        $this->file('src/One.php', 10, [['kind' => 'class', 'name' => 'One'], ['kind' => 'method', 'name' => 'One::run', 'contained_by' => 'One']]);
        $this->file('src/Two.php', 10, [['kind' => 'interface', 'name' => 'Two']]);
        $this->file('src/Three.php', 10, [['kind' => 'trait', 'name' => 'Three']]);
        $this->file('src/Four.php', 10, [['kind' => 'enum', 'name' => 'Four']]);
        $this->file('src/five.php', 10, [['kind' => 'function', 'name' => 'five'], ['kind' => 'function', 'name' => 'six']]);
        $this->file('src/Seven.php', 10, [['kind' => 'class', 'name' => 'Seven'], ['kind' => 'class', 'name' => 'Eight']]);
        $this->file('src/nine.php', 10, [['kind' => 'class', 'name' => 'Nine'], ['kind' => 'function', 'name' => 'nine', 'contained_by' => 'Nine']]);
        $this->file('src/empty.php', 10);

        self::assertSame(
            ['functions_only' => 1, 'mixed' => 1, 'one_class' => 2, 'one_enum' => 1, 'one_interface' => 1, 'one_trait' => 1],
            $this->sorted($this->facts('src')['kinds']),
        );
    }

    /** A function declared directly under the module counts, since a module is the file itself. */
    public function testAFunctionUnderTheModuleIsTopLevel(): void
    {
        $this->file('web/a.ts', 10, [['kind' => 'module', 'name' => 'web/a.ts'], ['kind' => 'function', 'name' => 'a', 'contained_by' => 'web/a.ts']], 'typescript');

        self::assertSame(['functions_only' => 1], $this->facts('web', 'ts')['kinds']);
    }

    /** The suffix is the last word most files share, and a name is never its own suffix. */
    public function testTheSharedSuffixIsTheMostCommonLastWord(): void
    {
        $this->file('src/ChurnService.php', 10, [['kind' => 'class', 'name' => 'ChurnService']]);
        $this->file('src/DiffService.php', 10, [['kind' => 'class', 'name' => 'DiffService']]);
        $this->file('src/Helper.php', 10, [['kind' => 'class', 'name' => 'Helper']]);

        self::assertSame(['value' => 'Service', 'files' => 2], $this->facts('src')['suffix']);
    }

    /** Names with nothing shared give no suffix at all. */
    public function testNoSharedSuffixIsOmitted(): void
    {
        $this->file('src/Helper.php', 10, [['kind' => 'class', 'name' => 'Helper']]);
        $this->file('src/Query.php', 10, [['kind' => 'class', 'name' => 'Query']]);

        self::assertArrayNotHasKey('suffix', $this->facts('src'));
    }

    /** Injected and used types are ranked by how many files have them, then by name, and capped at five. */
    public function testInjectedAndUsedTypesAreRankedByFilesThenName(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->file('src/' . $name . '.php', 10, [['kind' => 'class', 'name' => $name]]);
        }
        $this->file('lib/Clock.php', 10, [['kind' => 'class', 'name' => 'Clock']]);
        $this->file('lib/Log.php', 10, [['kind' => 'class', 'name' => 'Log']]);
        $this->file('lib/Zed.php', 10, [['kind' => 'class', 'name' => 'Zed']]);
        $this->edge('injects', 'A', 'Clock');
        $this->edge('injects', 'B', 'Clock');
        $this->edge('injects', 'A', 'Log');
        $this->edge('injects', 'C', 'Zed');
        $this->edge('constructs', 'A', 'Zed');
        $this->edge('references', 'A', 'Zed');
        $this->edge('references', 'B', 'Zed');
        $this->edge('references', 'B', 'A');

        $facts = $this->facts('src');

        self::assertSame(
            [['type' => 'Clock', 'files' => 2], ['type' => 'Log', 'files' => 1], ['type' => 'Zed', 'files' => 1]],
            $facts['injects'],
        );
        self::assertSame(
            [['type' => 'Zed', 'files' => 2]],
            $facts['uses'],
            'a file counts once per target however many edges it has, and a target in the same folder is not leaving it',
        );
    }

    /** Only the five widest targets are kept. */
    public function testOnlyTheTopFiveTargetsAreKept(): void
    {
        $this->file('src/A.php', 10, [['kind' => 'class', 'name' => 'A']]);
        foreach (range(1, 7) as $i) {
            $this->file('lib/T' . $i . '.php', 10, [['kind' => 'class', 'name' => 'T' . $i]]);
            $this->edge('injects', 'A', 'T' . $i);
        }

        self::assertSame(['T1', 'T2', 'T3', 'T4', 'T5'], array_column($this->facts('src')['injects'], 'type'));
    }

    /** The median is an int, the lower middle value for an even count, and the largest is kept. */
    public function testLinesAreTheMedianAndTheLargest(): void
    {
        foreach ([10, 20, 90] as $i => $lines) {
            $this->file('src/F' . $i . '.php', $lines);
        }
        foreach ([10, 20, 30, 40] as $i => $lines) {
            $this->file('other/F' . $i . '.php', $lines);
        }

        self::assertSame(['median' => 20, 'max' => 90], $this->facts('src')['lines']);
        self::assertSame(['median' => 20, 'max' => 40], $this->facts('other')['lines']);
    }

    /** The test path is learnt from the tests that reach a source file and carry its name. */
    public function testTheTestPathIsLearntFromTestsThatReachTheirSource(): void
    {
        foreach (['A', 'B'] as $name) {
            $this->file('src/Q/' . $name . '.php', 10, [['kind' => 'class', 'name' => $name]]);
            $this->file('tests/Q/' . $name . 'Test.php', 10, [['kind' => 'class', 'name' => $name . 'Test', 'role' => 'quality.test_module']]);
            $this->edge('calls', $name . 'Test', $name);
        }
        $this->file('src/Q/C.php', 10, [['kind' => 'class', 'name' => 'C']]);
        $this->file('tests/Q/SmokeTest.php', 10, [['kind' => 'class', 'name' => 'SmokeTest', 'role' => 'quality.test_module']]);
        $this->edge('calls', 'SmokeTest', 'C');

        self::assertSame(['pattern' => 'tests/Q/<name>Test.php', 'files' => 2], $this->facts('src/Q')['test_path']);
        self::assertArrayNotHasKey('test_path', $this->facts('tests/Q', 'php', 'test'));
    }

    /** Building the rows twice from one graph gives the same rows. */
    public function testTheSameGraphGivesTheSameRows(): void
    {
        $this->file('src/A.php', 10, [['kind' => 'class', 'name' => 'A']]);

        self::assertSame($this->rows(), $this->rows());
    }

    /**
     * A graph shaped like a real one (half the nodes in test files, tens of thousands of edges
     * leaving them) is folded within the scan's budget, test pairing included.
     */
    public function testRowsForARealisticGraphAreBuiltQuickly(): void
    {
        $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, 9)')
            ->execute([$this->ids['file'], $this->ids['project'], 'src/Checkout.php', 'h', 'php', '1', $this->ids['scan']]);
        SyntheticGraph::seed($this->pdo, $this->ids, 20000, 4);
        $project = $this->ids['project'];
        $this->pdo->exec(
            "INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count)
             WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < 399)
             SELECT 'bulk:' || i, '$project', CASE i % 2 WHEN 0 THEN 'tests/d' || (i % 40) || '/F' || i || 'Test.php' ELSE 'src/d' || (i % 40) || '/F' || i || '.php' END,
                    'h', 1, 1, 'php', '1', '{$this->ids['scan']}', 50 FROM seq",
        );
        $this->pdo->exec("UPDATE nodes SET file_id = 'bulk:' || (rowid % 400) WHERE project_id = '$project'");
        $this->pdo->exec(
            "INSERT INTO classifications (id, project_id, node_id, role, origin, confidence, rule_id, file_id, last_scan_id)
             SELECT 'c:' || id, project_id, id, 'quality.test_module', 'rule', 'certain', 'r', file_id, '{$this->ids['scan']}'
             FROM nodes WHERE project_id = '$project' AND rowid % 400 % 2 = 0 AND rowid % 400 < 399",
        );
        self::assertGreaterThan(30000, (int) $this->pdo->query("SELECT COUNT(*) FROM edges e JOIN classifications c ON c.node_id = e.source_id WHERE e.project_id = '$project'")->fetchColumn());
        $builder = new FolderFactsBuilder($this->pdo);

        $started = hrtime(true);
        $rows = $builder->rows($project);
        $milliseconds = (hrtime(true) - $started) / 1e6;

        self::assertGreaterThan(40, count($rows));
        self::assertLessThan(450.0, $milliseconds);
    }

    /** Directories compare as strings, so `10` sorts before `9` even though both are numeric. */
    public function testRowsOfNumericDirectoriesComeOutInStringOrder(): void
    {
        $this->file('9/A.php', 1);
        $this->file('10/A.php', 1);

        self::assertSame(['10', '9'], array_column($this->rows(), 'directory'));
    }

    /** @return list<array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}> */
    private function rows(): array
    {
        return (new FolderFactsBuilder($this->pdo))->rows($this->ids['project']);
    }

    /** @return array<string, mixed> */
    private function facts(string $directory, string $extension = 'php', string $role = 'source'): array
    {
        foreach ($this->rows() as $row) {
            if ([$row['directory'], $row['extension'], $row['role']] === [$directory, $extension, $role]) {
                return $row['facts'];
            }
        }
        self::fail('no row for ' . $directory . ' ' . $extension . ' ' . $role);
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<array-key, mixed>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * Insert a file and its nodes. A node may carry a role (classified), attributes, and the name of the node that contains it.
     *
     * @param list<array{kind: string, name: string, role?: string, attributes?: array<string, mixed>, contained_by?: string}> $nodes
     */
    private function file(string $path, int $lines, array $nodes = [], string $language = 'php'): void
    {
        $fileId = 'file:' . $path;
        $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id, line_count) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?, ?)')
            ->execute([$fileId, $this->ids['project'], $path, 'h', $language, '1', $this->ids['scan'], $lines]);
        foreach ($nodes as $node) {
            $id = 'node:' . $node['name'];
            $this->pdo->prepare('INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $this->ids['project'], $language, $node['kind'], $node['name'], $node['name'], $fileId, 'ast', 'certain', json_encode($node['attributes'] ?? [], JSON_THROW_ON_ERROR), 'k:' . $path, $this->ids['scan']]);
            if (isset($node['contained_by'])) {
                $this->edge('contains', $node['contained_by'], $node['name']);
            }
            if (isset($node['role'])) {
                $this->pdo->prepare('INSERT INTO classifications (id, project_id, node_id, role, origin, confidence, rule_id, file_id, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute(['class:' . $node['name'], $this->ids['project'], $id, $node['role'], 'rule', 'certain', 'r', $fileId, $this->ids['scan']]);
            }
        }
    }

    /** Insert an edge between two nodes named as inserted by `file()`. */
    private function edge(string $kind, string $from, string $to): void
    {
        $this->pdo->prepare('INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, origin, confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)')
            ->execute([$kind . ':' . $from . ':' . $to, $this->ids['project'], $kind, 'node:' . $from, 'node:' . $to, 'ast', 'certain', '{}', 'k', $this->ids['scan']]);
    }
}
