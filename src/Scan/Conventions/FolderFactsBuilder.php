<?php

declare(strict_types=1);

namespace Knossos\Scan\Conventions;

use PDO;

/**
 * Derives, from the committed graph, what the files in each folder have in common.
 *
 * One population is the direct children of a directory that share an extension
 * and a role (`test` when a node the file holds is classified
 * `quality.test_module`, otherwise `source`). The facts are plain counts and
 * rankings, so the same graph always yields the same rows, and a later query
 * can state a convention without reading any source. Each table is read once
 * and grouped in PHP: a folder never costs a query of its own.
 *
 * A file's top-level declarations are the class, interface, trait, enum and
 * function nodes it holds that no node other than a module contains. The
 * scanners store no parent link, so containment is the `contains` edge.
 */
final readonly class FolderFactsBuilder
{
    /** Declaration kinds that count, and whether each is a type (a function is not). */
    private const DECLARATIONS = ['class' => true, 'interface' => true, 'trait' => true, 'enum' => true, 'function' => false];

    /** Edge kinds that tie a test file to the source it exercises. */
    private const TEST_EDGES = ['calls', 'references', 'imports'];

    /** Target kinds that are members of something else, so naming one says nothing about a folder. */
    private const MEMBER_KINDS = ['method', 'external_method', 'property', 'variable'];

    /** How many injected and used types a row keeps. */
    private const TOP = 5;

    public function __construct(private PDO $pdo) {}

    /**
     * Every population of the project with its file count and facts, ordered by directory, extension, role.
     *
     * Facts are present only when there is data for them.
     *
     * @return list<array{directory: string, extension: string, role: string, files: int, facts: array<string, mixed>}>
     */
    public function rows(string $projectId): array
    {
        $tests = $this->testFileIds($projectId);
        /** @var array<string, array{directory: string, extension: string, role: string, path: string, lines: int, key: string}> $files */
        $files = [];
        /** @var array<string, array<string, mixed>> $populations */
        $populations = [];
        $statement = $this->pdo->prepare('SELECT id, relative_path, line_count FROM files WHERE project_id = ? ORDER BY relative_path');
        $statement->execute([$projectId]);
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$id, $path, $lines]) {
            $directory = PathParts::directory((string) $path);
            $extension = PathParts::extension((string) $path);
            $role = isset($tests[(string) $id]) ? 'test' : 'source';
            $key = $directory . "\0" . $extension . "\0" . $role;
            $files[(string) $id] = ['directory' => $directory, 'extension' => $extension, 'role' => $role, 'path' => (string) $path, 'lines' => (int) $lines, 'key' => $key];
            $populations[$key] ??= ['directory' => $directory, 'extension' => $extension, 'role' => $role, 'files' => 0, 'kinds' => [], 'modifiers' => [], 'suffixes' => [], 'injects' => [], 'uses' => [], 'lines' => [], 'pairs' => []];
            ++$populations[$key]['files'];
            $populations[$key]['lines'][] = (int) $lines;
        }

        $this->addDeclarations($projectId, $files, $populations);
        $this->addEdges($projectId, $files, $populations);
        $this->addTestPairs($projectId, $files, $tests, $populations);

        $rows = [];
        foreach ($populations as $population) {
            $rows[] = [
                'directory' => $population['directory'],
                'extension' => $population['extension'],
                'role' => $population['role'],
                'files' => $population['files'],
                'facts' => self::facts($population),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['directory'], $b['directory']) ?: strcmp($a['extension'], $b['extension']) ?: strcmp($a['role'], $b['role']));

        return $rows;
    }

    /**
     * The ids of the files that hold a test module.
     *
     * @return array<string, true>
     */
    private function testFileIds(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT n.file_id FROM classifications c JOIN nodes n ON n.id = c.node_id
             WHERE c.project_id = ? AND c.role = 'quality.test_module' AND n.file_id IS NOT NULL",
        );
        $statement->execute([$projectId]);

        return array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Fold each file's top-level declarations into kinds, modifiers and suffixes.
     *
     * @param array<string, array{directory: string, extension: string, role: string, path: string, lines: int, key: string}> $files
     * @param array<string, array<string, mixed>> $populations
     */
    private function addDeclarations(string $projectId, array $files, array &$populations): void
    {
        $statement = $this->pdo->prepare(
            "SELECT n.file_id, n.kind, n.display_name, n.attributes_json FROM nodes n
             WHERE n.project_id = ? AND n.file_id IS NOT NULL AND n.kind IN ('class', 'interface', 'trait', 'enum', 'function')
               AND NOT EXISTS (
                   SELECT 1 FROM edges e JOIN nodes s ON s.id = e.source_id
                   WHERE e.target_id = n.id AND e.kind = 'contains' AND s.kind <> 'module'
               )
             ORDER BY n.file_id, n.canonical_name",
        );
        $statement->execute([$projectId]);
        /** @var array<string, list<array{kind: string, name: string, attributes: string}>> $byFile */
        $byFile = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$fileId, $kind, $name, $attributes]) {
            if (isset($files[(string) $fileId])) {
                $byFile[(string) $fileId][] = ['kind' => (string) $kind, 'name' => (string) $name, 'attributes' => (string) $attributes];
            }
        }

        foreach ($byFile as $fileId => $declarations) {
            $key = $files[$fileId]['key'];
            $types = array_values(array_filter($declarations, static fn(array $d): bool => self::DECLARATIONS[$d['kind']]));
            if ($types === []) {
                $shape = 'functions_only';
            } elseif (count($declarations) === 1) {
                $shape = 'one_' . $types[0]['kind'];
                foreach (self::trueFlags($types[0]['attributes']) as $flag) {
                    $populations[$key]['modifiers'][$flag] = ($populations[$key]['modifiers'][$flag] ?? 0) + 1;
                }
            } else {
                $shape = 'mixed';
            }
            $populations[$key]['kinds'][$shape] = ($populations[$key]['kinds'][$shape] ?? 0) + 1;

            $candidates = [];
            foreach ($types as $type) {
                foreach (self::suffixes($type['name']) as $suffix) {
                    $candidates[$suffix] = true;
                }
            }
            foreach (array_keys($candidates) as $suffix) {
                $populations[$key]['suffixes'][$suffix] = ($populations[$key]['suffixes'][$suffix] ?? 0) + 1;
            }
        }
    }

    /**
     * Count, per population, the files that inject or use each type.
     *
     * @param array<string, array{directory: string, extension: string, role: string, path: string, lines: int, key: string}> $files
     * @param array<string, array<string, mixed>> $populations
     */
    private function addEdges(string $projectId, array $files, array &$populations): void
    {
        $members = "'" . implode("', '", self::MEMBER_KINDS) . "'";
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT CASE e.kind WHEN 'injects' THEN 'injects' ELSE 'uses' END, s.file_id, t.canonical_name, t.file_id
             FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id
             WHERE e.project_id = ? AND e.kind IN ('injects', 'references', 'imports', 'constructs')
               AND s.file_id IS NOT NULL AND t.kind NOT IN ($members)",
        );
        $statement->execute([$projectId]);
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$bucket, $sourceFile, $target, $targetFile]) {
            $source = $files[(string) $sourceFile] ?? null;
            if ($source === null) {
                continue;
            }
            $leaves = $targetFile === null || ($files[(string) $targetFile]['directory'] ?? null) !== $source['directory'];
            $field = $bucket === 'injects' ? 'injects' : 'uses';
            if ($field === 'uses' && !$leaves) {
                continue;
            }
            $populations[$source['key']][$field][(string) $target] = ($populations[$source['key']][$field][(string) $target] ?? 0) + 1;
        }
    }

    /**
     * Pair each source file with the test file that reaches it and is named after it.
     *
     * @param array<string, array{directory: string, extension: string, role: string, path: string, lines: int, key: string}> $files
     * @param array<string, true> $tests
     * @param array<string, array<string, mixed>> $populations
     */
    private function addTestPairs(string $projectId, array $files, array $tests, array &$populations): void
    {
        if ($tests === []) {
            return;
        }
        $kinds = "'" . implode("', '", self::TEST_EDGES) . "'";
        // Driven from the test files' nodes through the source index, with targets in test files dropped
        // and the pairs grouped in SQL, so PHP sees one row per pair of files and not one per edge.
        $statement = $this->pdo->prepare(
            "WITH test_files(file_id) AS (
                 SELECT DISTINCT n.file_id FROM classifications c JOIN nodes n ON n.id = c.node_id
                 WHERE c.project_id = ? AND c.role = 'quality.test_module' AND n.file_id IS NOT NULL
             )
             SELECT s.file_id, t.file_id FROM test_files JOIN nodes s ON s.file_id = test_files.file_id JOIN edges e ON e.source_id = s.id JOIN nodes t ON t.id = e.target_id
             WHERE e.kind IN ($kinds) AND t.file_id IS NOT NULL AND t.file_id NOT IN (SELECT file_id FROM test_files)
             GROUP BY s.file_id, t.file_id",
        );
        $statement->execute([$projectId]);
        /** @var array<string, string> $best the test file chosen for each source file */
        $best = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$testFile, $sourceFile]) {
            if (!isset($tests[(string) $testFile]) || isset($tests[(string) $sourceFile]) || !isset($files[(string) $testFile], $files[(string) $sourceFile])) {
                continue;
            }
            $testPath = $files[(string) $testFile]['path'];
            $stem = PathParts::stem($files[(string) $sourceFile]['path']);
            if ($stem === '' || !str_contains(basename($testPath), $stem)) {
                continue;
            }
            if (!isset($best[(string) $sourceFile]) || strcmp($testPath, $best[(string) $sourceFile]) < 0) {
                $best[(string) $sourceFile] = $testPath;
            }
        }
        foreach ($best as $sourceFile => $testPath) {
            $populations[$files[$sourceFile]['key']]['pairs'][] = ['source' => $files[$sourceFile]['path'], 'test' => $testPath];
        }
    }

    /**
     * The `facts` of one population, leaving out every key that has no data.
     *
     * @param array<string, mixed> $population
     * @return array<string, mixed>
     */
    private static function facts(array $population): array
    {
        $facts = [];
        if ($population['kinds'] !== []) {
            $facts['kinds'] = $population['kinds'];
        }
        if ($population['modifiers'] !== []) {
            $facts['modifiers'] = $population['modifiers'];
        }
        $suffix = self::commonSuffix($population['suffixes']);
        if ($suffix !== null) {
            $facts['suffix'] = $suffix;
        }
        foreach (['injects', 'uses'] as $key) {
            $top = self::top($population[$key]);
            if ($top !== []) {
                $facts[$key] = $top;
            }
        }
        if ($population['lines'] !== []) {
            $lines = $population['lines'];
            sort($lines);
            $facts['lines'] = ['median' => $lines[intdiv(count($lines) - 1, 2)], 'max' => $lines[count($lines) - 1]];
        }
        if ($population['role'] === 'source') {
            $pattern = TestPathPattern::learn($population['pairs']);
            if ($pattern !== null) {
                $facts['test_path'] = $pattern;
            }
        }

        return $facts;
    }

    /**
     * The suffix the most files share, longest first among equals, or null below two files.
     *
     * @param array<string, int> $counts files per suffix
     * @return array{value: string, files: int}|null
     */
    private static function commonSuffix(array $counts): ?array
    {
        $best = null;
        foreach ($counts as $suffix => $files) {
            $suffix = (string) $suffix;
            if ($best === null || self::betterSuffix($suffix, $files, $best)) {
                $best = ['value' => $suffix, 'files' => $files];
            }
        }

        return $best === null || $best['files'] < 2 ? null : $best;
    }

    /**
     * Whether a suffix beats the best so far: more files, then longer, then the smaller string.
     *
     * @param array{value: string, files: int} $best
     */
    private static function betterSuffix(string $suffix, int $files, array $best): bool
    {
        if ($files !== $best['files']) {
            return $files > $best['files'];
        }
        if (strlen($suffix) !== strlen($best['value'])) {
            return strlen($suffix) > strlen($best['value']);
        }

        return strcmp($best['value'], $suffix) > 0;
    }

    /**
     * The five most widespread types, by files then name.
     *
     * @param array<string, int> $counts files per type name
     * @return list<array{type: string, files: int}>
     */
    private static function top(array $counts): array
    {
        $names = array_map('strval', array_keys($counts));
        usort($names, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));

        return array_map(static fn(string $name): array => ['type' => $name, 'files' => $counts[$name]], array_slice($names, 0, self::TOP));
    }

    /**
     * The attribute names a node stored as boolean true.
     *
     * @return list<string>
     */
    private static function trueFlags(string $attributesJson): array
    {
        $attributes = json_decode($attributesJson, true);
        if (!is_array($attributes)) {
            return [];
        }

        return array_map('strval', array_keys(array_filter($attributes, static fn(mixed $value): bool => $value === true)));
    }

    /**
     * Every proper word suffix of a name ({@see NameWords}): `ChurnService` gives `Service`, never the whole name.
     *
     * @return list<string>
     */
    private static function suffixes(string $name): array
    {
        $suffixes = [];
        foreach (array_slice(NameWords::split($name), 1) as [, $offset]) {
            $suffixes[] = substr($name, $offset);
        }

        return $suffixes;
    }
}
