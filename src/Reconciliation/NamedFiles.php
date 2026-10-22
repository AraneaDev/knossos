<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\FilesystemContentReader;
use Knossos\Discovery\GitIgnoreRules;
use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\Manifest\ShellManifest;
use Knossos\Discovery\UnitKind;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\RelativePath;
use Knossos\Scanner\Protocol\ScanContribution;
use PDO;

/**
 * The project files no worker scans that something names: a path a process
 * the code starts runs (a `launches` edge), or the component a note is on.
 * Each is a `file` component, so it can be found and annotated and a launch
 * reaches it, though its contents are never read by a worker.
 *
 * Every shell script discovery found is one too, named or not, and what it
 * runs by path ({@see ShellManifest}) becomes its own `launches` edges, each
 * resolved like a worker's launch and dropped when no project file answers.
 * A file the script launches that no worker scans is then named by it.
 *
 * The scan makes them itself, as one contribution, recomputed every scan: a
 * file that is gone, excluded, or no longer named leaves the graph with it.
 * A named path counts only as written from the project root, inside it, and
 * as a regular file discovery did not already take as source.
 */
final readonly class NamedFiles
{
    /** The owner of the contribution, and so the scanner name its nodes carry. */
    public const OWNER = 'knossos.files:named';

    /** The most of one `.gitignore` read. */
    private const GITIGNORE_MAX_BYTES = 2_000_000;

    /** The reference a launch names its file by, after the language prefix. */
    private const LAUNCHED = ':launched_file:';

    public function __construct(private PDO $pdo) {}

    /**
     * The file components this scan has, sorted by path.
     *
     * @param list<ScanContribution> $contributions every contribution of the scan, reused ones included
     * @param list<string> $ignores the project's ignore patterns
     */
    public function contribution(string $projectId, DiscoveryResult $discovery, array $ignores, array $contributions): ScanContribution
    {
        $root = $discovery->rootRealpath;
        $scanned = array_fill_keys(array_map(static fn($file): string => $file->relativePath, $discovery->files), true);
        $matcher = new IgnoreMatcher($ignores);
        // What discovery leaves out the project's `.gitignore` files leave out here too.
        $gitIgnore = new GitIgnoreRules(static function (string $directory) use ($root): ?string {
            $path = $root . ($directory === '' ? '' : '/' . $directory) . '/.gitignore';

            return is_file($path) && !is_link($path) ? (new FilesystemContentReader())->read($path, self::GITIGNORE_MAX_BYTES)->bytes : null;
        });
        $component = static fn(string $path): bool => !isset($scanned[$path]) && self::isProjectFile($root, $path)
            && !$matcher->matchesWithAncestors($path) && !$gitIgnore->ignoresWithAncestors($path, false);
        $edges = [];
        $paths = [];
        foreach ($discovery->units as $unit) {
            if ($unit->kind !== UnitKind::Shell->value || !$component($unit->configPath)) {
                continue;
            }
            $paths[$unit->configPath] = true;
            foreach (self::launches($unit->metadata) as [$path, $line]) {
                // Only a file there is launched: the other readings of the path name nothing.
                if ($path === $unit->configPath || !self::isProjectFile($root, $path)) {
                    continue;
                }
                $edges[] = new EdgeFact(
                    'launches',
                    self::reference($unit->configPath),
                    'shell' . self::LAUNCHED . $path,
                    Origin::Derived,
                    Confidence::Probable,
                    new Evidence($unit->configPath, $line, $line),
                    ['speculative' => true],
                );
            }
        }
        foreach ($this->namedPaths($projectId, [...$contributions, new ScanContribution(self::OWNER, edges: $edges)]) as $path) {
            if ($component($path)) {
                $paths[$path] = true;
            }
        }
        ksort($paths, SORT_STRING);
        $nodes = [];
        foreach (array_keys($paths) as $path) {
            $path = (string) $path;
            $nodes[] = new NodeFact(
                self::reference($path),
                'file',
                $path,
                basename($path),
                Origin::Derived,
                Confidence::Certain,
                new Evidence($path, 1, 1),
                ['path' => $path],
            );
        }

        return new ScanContribution(self::OWNER, $nodes, $edges);
    }

    /** The reference a file component is declared and edged under. */
    private static function reference(string $path): string
    {
        return 'file:file:' . $path;
    }

    /**
     * What a shell unit's metadata says the script launches, as path and line.
     *
     * @param array<string, mixed> $metadata
     * @return list<array{0: string, 1: int}>
     */
    private static function launches(array $metadata): array
    {
        $launches = [];
        foreach (is_array($metadata['launches'] ?? null) ? $metadata['launches'] : [] as $launch) {
            if (is_array($launch) && is_string($launch['path'] ?? null) && is_int($launch['line'] ?? null) && $launch['line'] > 0) {
                $launches[] = [$launch['path'], $launch['line']];
            }
        }

        return $launches;
    }

    /**
     * The paths of the file components in the active graph, sorted: what a scan
     * that changed nothing on disk compares its own against.
     *
     * @return list<string>
     */
    public function current(string $projectId): array
    {
        $statement = $this->pdo->prepare("SELECT canonical_name FROM nodes WHERE project_id = ? AND kind = 'file' AND owner_key = ? ORDER BY canonical_name");
        $statement->execute([$projectId, self::OWNER]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The paths a launch or a note names, each once, sorted.
     *
     * @param list<ScanContribution> $contributions
     * @return list<string>
     */
    private function namedPaths(string $projectId, array $contributions): array
    {
        $paths = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $at = strpos($edge->targetReference, self::LAUNCHED);
                if ($edge->kind === 'launches' && $at !== false) {
                    $paths[substr($edge->targetReference, $at + strlen(self::LAUNCHED))] = true;
                }
            }
        }
        $statement = $this->pdo->prepare('SELECT DISTINCT canonical_name FROM annotations WHERE project_id = ?');
        $statement->execute([$projectId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $paths[(string) $name] = true;
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return array_map('strval', $paths);
    }

    /**
     * Whether `$path`, as written from the root, is a regular file inside it.
     *
     * The path becomes the component's evidence, so it is held to the strict,
     * normalised form evidence requires: a looser check let a backslash or a
     * drive letter through to {@see Evidence}, which then refused it and
     * aborted the scan.
     */
    private static function isProjectFile(string $root, string $path): bool
    {
        try {
            RelativePath::assertValid($path);
        } catch (InvalidArgumentException) {
            return false;
        }
        $absolute = $root . '/' . $path;
        if (!is_file($absolute)) {
            return false;
        }
        $real = realpath($absolute);

        return $real !== false && str_starts_with($real, rtrim($root, '/') . '/');
    }
}
